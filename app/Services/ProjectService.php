<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * プロジェクトとメンバーの管理(社内メンバーのみ操作可)
 */
class ProjectService
{
    /** プロジェクト管理画面の1ページあたりの表示件数 */
    public const PER_PAGE = 20;

    /**
     * プロジェクト管理画面の一覧(メンバー数・未完了の課題数つき)
     */
    public function paginateForManagement(): LengthAwarePaginator
    {
        return Project::query()
            ->withCount([
                'members',
                'issues as open_issues_count' => fn ($q) => $q->where('status', '!=', IssueStatus::Closed->value),
            ])
            ->orderBy('name')
            ->paginate(self::PER_PAGE);
    }

    /**
     * 全プロジェクト(ユーザーの参加プロジェクト選択用)
     *
     * @return Collection<int, Project>
     */
    public function all(): Collection
    {
        return Project::orderBy('name')->get();
    }

    /**
     * 編集画面用に、メンバーを「社内 → 会社名 → 氏名」の順で読み込む
     */
    public function loadMembers(Project $project): Project
    {
        return $project->load(['members' => fn ($q) => $q->orderBy('role', 'desc')->orderBy('company_name')->orderBy('name')]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int|string>  $memberIds
     */
    public function create(array $data, array $memberIds = []): Project
    {
        return DB::transaction(function () use ($data, $memberIds) {
            $project = Project::create([
                'key' => strtoupper($data['key']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $project->members()->sync($memberIds);

            return $project;
        });
    }

    /**
     * プロジェクトキーはURLや課題キーに使われているため変更しない
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        $project->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return $project;
    }

    /**
     * プロジェクトを削除する(論理削除。課題はそのまま残るが画面からは見えなくなる。ゴミ箱から元に戻せる)
     */
    public function delete(Project $project, User $actor): void
    {
        $project->trashBy($actor);
    }

    /**
     * メンバーに追加し、追加したユーザーを返す
     */
    public function addMember(Project $project, int $userId): User
    {
        $user = User::findOrFail($userId);
        $project->members()->syncWithoutDetaching([$user->id]);

        return $user;
    }

    public function removeMember(Project $project, User $user): void
    {
        $project->members()->detach($user->id);
    }
}
