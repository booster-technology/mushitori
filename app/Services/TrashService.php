<?php

namespace App\Services;

use App\Exceptions\TrashException;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ゴミ箱: 論理削除したデータ(プロジェクト・課題・コメント・添付ファイル)の一覧・復元・完全削除。
 * - 削除したデータは保管期間(.env の TRASH_RETENTION_DAYS。既定30日)のあいだ残し、元に戻せる
 * - 保管期間を過ぎたものは、毎日の自動処理(app:purge-trash)で完全に削除する
 * - 個人情報を誤って添付した場合などは、社内メンバーがゴミ箱からすぐに完全削除できる
 * - 完全削除は、画像などの添付ファイルの実体もサーバーから消す。
 *   コメントは、課題の経緯が分からなくならないよう、状態などの変更履歴だけを残す
 * アプリの中で物理削除(forceDelete・ファイルの削除)をするのは、このクラスだけ
 */
class TrashService
{
    public const PER_PAGE = 20;

    /** ゴミ箱の種類(URLの値 => 表示名) */
    public const TYPES = [
        'issues' => '課題',
        'comments' => 'コメント',
        'attachments' => '添付ファイル',
        'projects' => 'プロジェクト',
    ];

    private const DISK = 'local';

    /**
     * 保管期間(日)。0なら自動では削除しない
     */
    public function retentionDays(): int
    {
        return max(0, (int) config('trash.retention_days'));
    }

    /**
     * 自動で完全に削除される日時(保管期間が0なら null)
     */
    public function purgesAt(CarbonInterface $deletedAt): ?CarbonInterface
    {
        $days = $this->retentionDays();

        return $days > 0 ? $deletedAt->copy()->addDays($days) : null;
    }

    /**
     * 種類ごとの件数(タブに表示する)
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (array_keys(self::TYPES) as $type) {
            $counts[$type] = $this->query($type)->count();
        }

        return $counts;
    }

    /**
     * 削除済みの一覧(新しく削除したものから)。画面表示用に trash_label(名前)・purges_at(完全削除の予定日時)を付ける
     */
    public function list(string $type): LengthAwarePaginator
    {
        $withProject = ['project' => fn ($q) => $q->withTrashed()];
        $withIssue = ['issue' => fn ($q) => $q->withTrashed()->with($withProject)];

        $query = $this->query($type)->with('deletedBy')->latest('deleted_at')->latest('id');
        match ($type) {
            'issues' => $query->with($withProject),
            'comments' => $query->with(['user', ...$withIssue])->withCount(['attachments' => fn ($q) => $q->withTrashed()]),
            'attachments' => $query->with(['user', ...$withIssue]),
            'projects' => $query->withCount('issues'),
        };

        return $query->paginate(self::PER_PAGE)->withQueryString()->through(function (Model $item) use ($type) {
            $item->setAttribute('trash_label', $this->label($type, $item));
            $item->setAttribute('purges_at', $this->purgesAt($item->deleted_at));

            return $item;
        });
    }

    /**
     * ゴミ箱から元に戻し、元に戻したものの名前を返す。
     * 親(プロジェクト・課題)が削除されたままの場合は、戻しても見えないため TrashException にする
     */
    public function restore(string $type, int $id): string
    {
        $item = $this->find($type, $id);
        $label = $this->label($type, $item);

        match ($type) {
            'issues' => $this->ensureProjectExists($item->project_id),
            'comments', 'attachments' => $this->ensureIssueExists($item->issue_id),
            'projects' => null,
        };

        DB::transaction(function () use ($type, $item) {
            $item->restoreFromTrash();
            // コメントの添付は、コメントと一緒に削除しているので一緒に戻す
            if ($type === 'comments') {
                Attachment::onlyTrashed()->where('comment_id', $item->id)->get()->each->restoreFromTrash();
            }
        });

        return $label;
    }

    /**
     * ゴミ箱から完全に削除し(画像などの添付ファイルの実体も消す)、削除したものの名前を返す。元に戻せない
     */
    public function purge(string $type, int $id, User $actor): string
    {
        $item = $this->find($type, $id);
        $label = $this->label($type, $item);

        $this->purgeItem($type, $item);

        // 誰が完全に削除したかを記録する(名前は個人情報を含むことがあるので出さない)
        Log::info('ゴミ箱から完全に削除しました', ['type' => $type, 'id' => $id, 'user_id' => $actor->id]);

        return $label;
    }

    /**
     * 保管期間を過ぎたものを完全に削除する(毎日の自動処理)。種類ごとの削除件数を返す
     *
     * @return array<string, int>
     */
    public function purgeExpired(): array
    {
        $counts = array_fill_keys(array_keys(self::TYPES), 0);
        $days = $this->retentionDays();
        if ($days === 0) {
            return $counts;
        }

        $cutoff = now()->subDays($days);
        // 親から順に削除する(プロジェクトを消すと、その課題・コメント・添付も一緒に消える)
        foreach (['projects', 'issues', 'comments', 'attachments'] as $type) {
            $this->query($type)->where('deleted_at', '<=', $cutoff)->lazyById()->each(function (Model $item) use ($type, &$counts) {
                $this->purgeItem($type, $item);
                $counts[$type]++;
            });
        }

        if (array_sum($counts) > 0) {
            Log::info('保管期間を過ぎた削除済みデータを完全に削除しました', $counts + ['retention_days' => $days]);
        }

        return $counts;
    }

    /**
     * 画面・メッセージに表示する名前
     */
    public function label(string $type, Model $item): string
    {
        return match ($type) {
            'issues' => $this->issueKey($item).' '.$item->title,
            'comments' => $this->issueKey($this->issueOf($item)).' のコメント',
            'attachments' => $item->original_name,
            'projects' => $item->key.' '.$item->name,
        };
    }

    /**
     * 種類ごとの「削除済み」の検索条件
     *
     * @return Builder<Model>
     */
    private function query(string $type): Builder
    {
        return match ($type) {
            'issues' => Issue::onlyTrashed(),
            // 完全に削除して変更履歴だけを残したコメントは除く
            'comments' => Comment::onlyTrashed()->whereNull('purged_at'),
            // コメントの添付はコメントと一緒に扱うので、課題本文から削除した添付だけを表示する
            'attachments' => Attachment::onlyTrashed()->whereNull('comment_id'),
            'projects' => Project::onlyTrashed(),
            default => abort(404),
        };
    }

    private function find(string $type, int $id): Model
    {
        return $this->query($type)->findOrFail($id);
    }

    private function issueOf(Model $item): ?Issue
    {
        return $item->relationLoaded('issue') && $item->issue !== null
            ? $item->issue
            : Issue::withTrashed()->find($item->issue_id);
    }

    private function issueKey(?Issue $issue): string
    {
        if ($issue === null) {
            return '';
        }
        $project = $issue->relationLoaded('project') && $issue->project !== null
            ? $issue->project
            : Project::withTrashed()->find($issue->project_id);

        return ($project?->key ?? '?').'-'.$issue->number;
    }

    private function ensureProjectExists(int $projectId): void
    {
        $project = Project::withTrashed()->find($projectId);
        if ($project?->trashed()) {
            throw new TrashException("プロジェクト「{$project->name}」が削除されているため、元に戻せません。先にプロジェクトを元に戻してください。");
        }
    }

    private function ensureIssueExists(int $issueId): void
    {
        $issue = Issue::withTrashed()->find($issueId);
        if ($issue?->trashed()) {
            throw new TrashException("課題「{$this->issueKey($issue)}」が削除されているため、元に戻せません。先に課題を元に戻してください。");
        }
        if ($issue !== null) {
            $this->ensureProjectExists($issue->project_id);
        }
    }

    /**
     * 完全に削除する。DBの削除が確定してから添付ファイルの実体を消す
     */
    private function purgeItem(string $type, Model $item): void
    {
        $paths = DB::transaction(fn () => match ($type) {
            'projects' => $this->purgeProject($item),
            'issues' => $this->purgeIssue($item),
            'comments' => $this->purgeComment($item),
            'attachments' => $this->purgeAttachment($item),
        });

        if ($paths !== []) {
            try {
                Storage::disk(self::DISK)->delete($paths);
            } catch (Throwable $e) {
                Log::warning('完全に削除した添付ファイルの実体を消せませんでした', ['paths' => $paths, 'exception' => $e]);
            }
        }
    }

    /**
     * @return list<string> 消す添付ファイルの実体
     */
    private function purgeAttachment(Attachment $attachment): array
    {
        $paths = array_values(array_filter([$attachment->path, $attachment->thumbnail_path]));
        $attachment->forceDelete();

        return $paths;
    }

    /**
     * コメントの本文・添付を消す。状態などの変更履歴があれば課題の経緯として残し、無ければコメントごと消す
     *
     * @return list<string>
     */
    private function purgeComment(Comment $comment): array
    {
        $paths = [];
        foreach (Attachment::withTrashed()->where('comment_id', $comment->id)->get() as $attachment) {
            array_push($paths, ...$this->purgeAttachment($attachment));
        }

        if (empty($comment->change_log)) {
            $comment->forceDelete();
        } else {
            $comment->forceFill(['body' => null, 'purged_at' => now()])->saveQuietly();
        }

        return $paths;
    }

    /**
     * 課題を、コメント・添付ファイルごと消す
     *
     * @return list<string>
     */
    private function purgeIssue(Issue $issue): array
    {
        $paths = [];
        foreach (Attachment::withTrashed()->where('issue_id', $issue->id)->get() as $attachment) {
            array_push($paths, ...$this->purgeAttachment($attachment));
        }
        Comment::withTrashed()->where('issue_id', $issue->id)->forceDelete();
        $issue->forceDelete();

        return $paths;
    }

    /**
     * プロジェクトを、課題・コメント・添付ファイル・メンバーの設定ごと消す
     *
     * @return list<string>
     */
    private function purgeProject(Project $project): array
    {
        $paths = [];
        foreach (Issue::withTrashed()->where('project_id', $project->id)->lazyById() as $issue) {
            array_push($paths, ...$this->purgeIssue($issue));
        }
        $project->members()->detach();
        $project->forceDelete();

        return $paths;
    }
}
