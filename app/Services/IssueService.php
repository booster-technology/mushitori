<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 課題の登録・編集・削除を担当する
 */
class IssueService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * 課題を登録する。課題番号はプロジェクト単位の連番で、同時登録でも重複しないよう行ロックして採番する
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(Project $project, User $actor, array $data, array $files = []): Issue
    {
        /** @var list<Attachment> $stored */
        $stored = [];

        // 代理起票: 社内メンバーは、電話・メールで受けた内容をお客様の課題として登録できる
        // (起票者がお客様になるので、対応の経過がお客様にも通知される)。お客様の入力値は無視する
        $reporterId = $actor->isStaff() && ! empty($data['reporter_id']) ? (int) $data['reporter_id'] : $actor->id;

        try {
            $issue = DB::transaction(function () use ($project, $actor, $data, $files, $reporterId, &$stored) {
                $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $number = $locked->next_issue_number;
                $locked->increment('next_issue_number');

                $issue = Issue::create([
                    'project_id' => $project->id,
                    'number' => $number,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'type' => $data['type'],
                    'priority' => $data['priority'],
                    'status' => IssueStatus::Open,
                    'reporter_id' => $reporterId,
                    // 代わりに登録した人(本人が登録した場合は記録しない)
                    'registered_by' => $reporterId !== $actor->id ? $actor->id : null,
                    // 担当者・期限日は社内メンバーのみ設定できる
                    'assignee_id' => $actor->isStaff() ? ($data['assignee_id'] ?? null) : null,
                    'due_date' => $actor->isStaff() ? ($data['due_date'] ?? null) : null,
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $issue, $actor);
                }

                return $issue;
            });
        } catch (Throwable $e) {
            // DBはロールバックされるが保存済みファイルは残るので削除しておく
            foreach ($stored as $attachment) {
                $this->attachments->deleteFiles($attachment);
            }
            throw $e;
        }

        $issue->load(['project', 'reporter', 'registeredBy', 'assignee']);
        $this->notifications->issueCreated($issue, $actor);

        return $issue;
    }

    /**
     * 課題の件名・種別・詳細・添付ファイルを更新する
     * (状態・担当者・優先度・期限日はコメント欄から変更し、履歴を残す)
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     * @param  list<int|string>  $removeAttachmentIds
     */
    public function update(Issue $issue, User $actor, array $data, array $files = [], array $removeAttachmentIds = []): Issue
    {
        /** @var list<Attachment> $stored */
        $stored = [];

        try {
            DB::transaction(function () use ($issue, $actor, $data, $files, $removeAttachmentIds, &$stored) {
                $issue->update([
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'description' => $data['description'] ?? null,
                ]);

                // 削除対象は課題本文の添付のうち、削除権限のあるものだけ(社内は全て、お客様は自分が添付したもの)
                $targets = $issue->attachments()
                    ->whereIn('id', $removeAttachmentIds)
                    ->get()
                    ->filter(fn (Attachment $a) => $actor->isStaff() || $a->user_id === $actor->id);
                // 論理削除(データ・ファイルは残し、画面・配信からは見えなくする)
                foreach ($targets as $attachment) {
                    $this->attachments->delete($attachment, $actor);
                }

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $issue, $actor);
                }
            });
        } catch (Throwable $e) {
            // 登録に失敗した場合は、保存済みのファイルがDBに記録されないまま残らないよう片付ける
            foreach ($stored as $attachment) {
                $this->attachments->deleteFiles($attachment);
            }
            throw $e;
        }

        return $issue;
    }

    /**
     * 詳細画面用に、起票者・担当者・添付・コメント(削除済みも含めて時系列順。変更履歴を残すため)を読み込む
     */
    public function loadForDisplay(Issue $issue): Issue
    {
        return $issue->load([
            'project',
            'reporter',
            'registeredBy',
            'assignee',
            'attachments',
            'comments' => fn ($q) => $q->withTrashed()->with(['user', 'attachments'])->orderBy('created_at')->orderBy('id'),
        ]);
    }

    /**
     * 編集画面用に、プロジェクトと添付ファイルを読み込む
     */
    public function loadForEdit(Issue $issue): Issue
    {
        return $issue->load(['project', 'attachments']);
    }

    /**
     * 課題を削除する(論理削除。コメント・添付ファイルも残り、ゴミ箱から元に戻せる)
     */
    public function delete(Issue $issue, User $actor): void
    {
        $issue->trashBy($actor);
    }
}
