<?php

namespace App\Services;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 課題一覧の検索条件・並び順を組み立てる
 */
class IssueQueryService
{
    /** 1ページあたりの表示件数 */
    public const PER_PAGE = 20;

    /** 並び順の選択肢 */
    public const SORTS = [
        'updated' => '更新日時が新しい順',
        'created' => '登録日時が新しい順',
        'priority' => '優先度が高い順',
        'due_date' => '期限日が近い順',
    ];

    /** 状態の絞り込みの選択肢(個別の状態に加えて「完了以外」「すべて」) */
    public const STATUS_FILTERS = [
        'unclosed' => '完了以外',
        'all' => 'すべて',
    ];

    /**
     * プロジェクトの課題一覧を検索する
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(Project $project, array $filters, User $viewer): LengthAwarePaginator
    {
        $query = Issue::query()
            ->where('project_id', $project->id)
            ->with(['project', 'assignee', 'reporter'])
            ->withCount(['comments', 'allAttachments']);

        $this->applyFilters($query, $project, $filters, $viewer);
        $this->applySort($query, $filters['sort'] ?? 'updated');

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * 画面で使う絞り込み条件を正規化する(未指定・不正値はデフォルトに)
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function normalize(array $input): array
    {
        // ?q[]=x のように配列で送られてきた値は、未指定として扱う(文字列への変換でエラーにしない)
        $string = fn (string $key, string $default = ''): string => is_string($input[$key] ?? null) ? $input[$key] : $default;

        $statusValues = array_merge(array_keys(self::STATUS_FILTERS), array_column(IssueStatus::cases(), 'value'));
        $status = $string('status', 'unclosed');
        $sort = $string('sort', 'updated');

        return [
            'status' => in_array($status, $statusValues, true) ? $status : 'unclosed',
            'type' => $string('type'),
            'priority' => $string('priority'),
            'assignee' => $string('assignee'),
            'q' => trim($string('q')),
            'sort' => array_key_exists($sort, self::SORTS) ? $sort : 'updated',
        ];
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, Project $project, array $filters, User $viewer): void
    {
        $status = $filters['status'] ?? 'unclosed';
        if ($status === 'unclosed') {
            $query->where('status', '!=', IssueStatus::Closed->value);
        } elseif ($status !== 'all' && IssueStatus::tryFrom($status)) {
            $query->where('status', $status);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        $assignee = $filters['assignee'] ?? '';
        if ($assignee === 'me') {
            $query->where('assignee_id', $viewer->id);
        } elseif ($assignee === 'none') {
            $query->whereNull('assignee_id');
        } elseif (ctype_digit((string) $assignee)) {
            $query->where('assignee_id', (int) $assignee);
        }

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            // 「WEB-12」のような課題キーで検索されたら番号で絞り込む
            if (preg_match('/^'.preg_quote($project->key, '/').'-(\d+)$/i', $keyword, $m)) {
                $query->where('number', (int) $m[1]);
            } else {
                $like = like_contains($keyword);
                $query->where(function (Builder $q) use ($like) {
                    $q->whereRaw("title LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$like]);
                });
            }
        }
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'created' => $query->orderByDesc('created_at'),
            'priority' => $query->orderByRaw(IssuePriority::orderSql())->orderByDesc('updated_at'),
            // 期限日なしは最後に回す
            'due_date' => $query->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date'),
            default => $query->orderByDesc('updated_at'),
        };
        $query->orderByDesc('id');
    }
}
