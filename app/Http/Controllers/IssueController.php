<?php

namespace App\Http\Controllers;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Services\IssueQueryService;
use App\Services\IssueService;
use App\Services\UserService;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/**
 * 課題の一覧・登録・詳細・編集・削除(社内/お客様の両ポータル共通)
 */
class IssueController extends Controller
{
    public function __construct(
        private readonly IssueService $issues,
        private readonly IssueQueryService $issueQuery,
        private readonly UserService $users,
    ) {}

    /**
     * プロジェクトの課題一覧
     */
    public function index(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $filters = $this->issueQuery->normalize($request->query());
        $issues = $this->issueQuery->search($project, $filters, $request->user());

        // 詳細画面の「一覧に戻る」で絞り込み条件を復元できるよう、表示中のURLを覚えておく
        $request->session()->put($this->listUrlKey($project), $request->fullUrl());

        return view('issues.index', [
            'project' => $project,
            'issues' => $issues,
            'filters' => $filters,
            // 担当者での絞り込みの選択肢。お客様には、このプロジェクトの課題の担当者だけを表示する
            'staffUsers' => $request->user()->isStaff() ? $this->users->assignableStaff() : $this->users->assigneesOf($project),
        ]);
    }

    /**
     * 課題の新規登録画面
     */
    public function create(Request $request, Project $project): View
    {
        Gate::authorize('createIssue', $project);

        $type = IssueType::tryFrom($this->queryString($request, 'type')) ?? IssueType::Bug;

        return view('issues.create', [
            'project' => $project,
            'defaultType' => $type,
            'defaultPriority' => IssuePriority::Normal,
            'staffUsers' => $this->users->assignableStaff(),
            'templates' => collect(IssueType::cases())
                ->mapWithKeys(fn (IssueType $t) => [$t->value => $t->descriptionTemplate()]),
        ]);
    }

    /**
     * 課題を登録する
     */
    public function store(StoreIssueRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('createIssue', $project);

        try {
            $issue = $this->issues->create(
                $project,
                $request->user(),
                $request->validated(),
                $request->file('attachments', [])
            );
        } catch (Throwable $e) {
            return $this->failed($e, '課題の登録に失敗しました');
        }

        return redirect()->to(Portal::route('issues.show', $issue))
            ->with('success', "課題 {$issue->issueKey()} を登録しました。");
    }

    /**
     * 課題の詳細画面(コメント・変更履歴を含む)
     */
    public function show(Request $request, Issue $issue): View
    {
        Gate::authorize('view', $issue);

        $issue = $this->issues->loadForDisplay($issue);

        return view('issues.show', [
            'issue' => $issue,
            'staffUsers' => $this->users->assignableStaff(),
            'backUrl' => $request->session()->get(
                $this->listUrlKey($issue->project),
                Portal::route('issues.index', $issue->project)
            ),
        ]);
    }

    /**
     * 課題の編集画面
     */
    public function edit(Issue $issue): View
    {
        Gate::authorize('update', $issue);

        return view('issues.edit', ['issue' => $this->issues->loadForEdit($issue)]);
    }

    /**
     * 課題を更新する
     */
    public function update(UpdateIssueRequest $request, Issue $issue): RedirectResponse
    {
        Gate::authorize('update', $issue);

        try {
            $this->issues->update(
                $issue,
                $request->user(),
                $request->validated(),
                $request->file('attachments', []),
                $request->validated('remove_attachments') ?? []
            );
        } catch (Throwable $e) {
            return $this->failed($e, '課題の更新に失敗しました');
        }

        return redirect()->to(Portal::route('issues.show', $issue))
            ->with('success', '課題を更新しました。');
    }

    /**
     * 課題を削除する(社内メンバーのみ)
     */
    public function destroy(Request $request, Issue $issue): RedirectResponse
    {
        Gate::authorize('delete', $issue);

        $project = $issue->project;
        $key = $issue->issueKey();

        try {
            $this->issues->delete($issue, $request->user());
        } catch (Throwable $e) {
            return $this->failed($e, '課題の削除に失敗しました');
        }

        return redirect()->to(Portal::route('issues.index', $project))
            ->with('success', "課題 {$key} を削除しました。");
    }

    private function listUrlKey(Project $project): string
    {
        return 'issue_list_url.'.portal().'.'.$project->id;
    }
}
