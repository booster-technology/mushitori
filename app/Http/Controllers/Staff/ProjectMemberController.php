<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectMemberRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * プロジェクトメンバーの追加・削除(社内メンバー専用)
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    public function store(AddProjectMemberRequest $request, Project $project): RedirectResponse
    {
        try {
            $user = $this->projects->addMember($project, (int) $request->validated('user_id'));
        } catch (Throwable $e) {
            return $this->failed($e, 'メンバーの追加に失敗しました');
        }

        return redirect()->route('staff.projects.edit', $project)
            ->with('success', "{$user->displayName()} をメンバーに追加しました。");
    }

    /**
     * ルートの {project}/{user} の順で引数を受け取る
     */
    public function destroy(Project $project, User $user): RedirectResponse
    {
        try {
            $this->projects->removeMember($project, $user);
        } catch (Throwable $e) {
            return $this->failed($e, 'メンバーの削除に失敗しました');
        }

        return redirect()->route('staff.projects.edit', $project)
            ->with('success', "{$user->displayName()} をメンバーから外しました。");
    }
}
