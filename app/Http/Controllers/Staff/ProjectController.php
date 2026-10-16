<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * プロジェクト管理(社内メンバー専用)
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly UserService $users,
    ) {}

    public function index(): View
    {
        return view('staff.projects.index', [
            'projects' => $this->projects->paginateForManagement(),
        ]);
    }

    public function create(): View
    {
        return view('staff.projects.create', [
            'users' => $this->users->memberCandidates(),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $project = $this->projects->create($validated, $validated['members'] ?? []);
        } catch (Throwable $e) {
            return $this->failed($e, 'プロジェクトの作成に失敗しました');
        }

        return redirect()->route('staff.projects.edit', $project)
            ->with('success', "プロジェクト「{$project->name}」を作成しました。");
    }

    /**
     * 編集画面(メンバー管理を含む)
     */
    public function edit(Project $project): View
    {
        $project = $this->projects->loadMembers($project);

        return view('staff.projects.edit', [
            'project' => $project,
            // メンバー追加の選択肢(まだメンバーでない有効なユーザー)
            'candidates' => $this->users->memberCandidates($project->members->modelKeys()),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        try {
            $this->projects->update($project, $request->validated());
        } catch (Throwable $e) {
            return $this->failed($e, 'プロジェクトの更新に失敗しました');
        }

        return redirect()->route('staff.projects.edit', $project)
            ->with('success', 'プロジェクトを更新しました。');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        try {
            $this->projects->delete($project, $request->user());
        } catch (Throwable $e) {
            return $this->failed($e, 'プロジェクトの削除に失敗しました');
        }

        return redirect()->route('staff.projects.index')
            ->with('success', "プロジェクト「{$project->name}」を削除しました。");
    }
}
