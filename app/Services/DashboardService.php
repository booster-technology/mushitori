<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ホーム画面に表示する内容(プロジェクト一覧・自分の担当課題・最近の更新)を集める
 */
class DashboardService
{
    /** 「最近更新された課題」の表示件数 */
    public const RECENT_LIMIT = 10;

    /**
     * @return array{projects: Collection<int, Project>, recentIssues: Collection<int, Issue>, myIssues: Collection<int, Issue>}
     */
    public function forUser(User $user): array
    {
        $projects = $this->accessibleProjects($user);
        $projectIds = $projects->pluck('id');

        return [
            'projects' => $projects,
            'recentIssues' => $this->recentIssues($projectIds),
            // 社内メンバーには自分が担当している未完了の課題を出す
            'myIssues' => $user->isStaff() ? $this->assignedOpenIssues($user, $projectIds) : collect(),
        ];
    }

    /**
     * 閲覧できるプロジェクト(社内は全件、お客様は参加しているもの)と、状態ごとの課題数(status_counts)
     *
     * @return Collection<int, Project>
     */
    public function accessibleProjects(User $user): Collection
    {
        $query = Project::query()->orderBy('name');
        if (! $user->isStaff()) {
            $query->whereHas('members', fn (Builder $q) => $q->whereKey($user->id));
        }

        $projects = $query->get();

        // 状態ごとの件数をまとめて取得して各プロジェクトに持たせる
        $counts = Issue::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, status, COUNT(*) as total')
            ->groupBy('project_id', 'status')
            ->toBase() // 集計値なのでEnumキャストを通さず生の値で受け取る
            ->get()
            ->groupBy('project_id');

        return $projects->each(function (Project $project) use ($counts) {
            $byStatus = collect($counts->get($project->id, []))->pluck('total', 'status');
            $project->setAttribute('status_counts', collect(IssueStatus::cases())
                ->mapWithKeys(fn (IssueStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]));
        });
    }

    /**
     * @param  Collection<int, int>  $projectIds
     * @return Collection<int, Issue>
     */
    private function recentIssues(Collection $projectIds): Collection
    {
        return Issue::query()
            ->whereIn('project_id', $projectIds)
            ->with(['project', 'assignee'])
            ->orderByDesc('updated_at')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * 自分が担当している未完了の課題(期限日が近い順、期限なしは最後)
     *
     * @param  Collection<int, int>  $projectIds
     * @return Collection<int, Issue>
     */
    private function assignedOpenIssues(User $user, Collection $projectIds): Collection
    {
        return Issue::query()
            ->whereIn('project_id', $projectIds)
            ->where('assignee_id', $user->id)
            ->where('status', '!=', IssueStatus::Closed->value)
            ->with(['project', 'assignee'])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('updated_at')
            ->get();
    }
}
