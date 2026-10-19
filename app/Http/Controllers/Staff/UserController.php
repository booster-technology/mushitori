<?php

namespace App\Http\Controllers\Staff;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveUserRequest;
use App\Models\User;
use App\Services\LoginCodeService;
use App\Services\ProjectService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * ユーザー管理(社内メンバー専用)
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly ProjectService $projects,
        private readonly LoginCodeService $loginCodes,
    ) {}

    public function index(Request $request): View
    {
        $role = UserRole::tryFrom($this->queryString($request, 'role'));
        $keyword = trim($this->queryString($request, 'q'));

        $users = $this->users->search($role, $keyword);

        return view('staff.users.index', [
            'users' => $users,
            // 認証コードの入力ミスでログインがロックされているユーザー(一覧に「ロック中」と表示)
            'lockedIds' => $this->loginCodes->lockedUserIds($users),
            'role' => $role?->value ?? '',
            'keyword' => $keyword,
        ]);
    }

    public function create(Request $request): View
    {
        return view('staff.users.create', [
            'projects' => $this->projects->all(),
            'defaultRole' => UserRole::tryFrom($this->queryString($request, 'role')) ?? UserRole::Customer,
        ]);
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $user = $this->users->create(
                $validated,
                $validated['projects'] ?? [],
                $request->boolean('send_welcome_mail')
            );
        } catch (Throwable $e) {
            return $this->failed($e, 'ユーザーの作成に失敗しました');
        }

        return redirect()->route('staff.users.index')
            ->with('success', "ユーザー「{$user->name}」を作成しました。");
    }

    public function edit(User $user): View
    {
        return view('staff.users.edit', [
            'user' => $this->users->loadForEdit($user),
            'projects' => $this->projects->all(),
            // ロック中なら、自動で解除されるまでの分数(ロックされていなければ null)
            'lockedMinutes' => $this->loginCodes->lockedMinutes($user),
        ]);
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->users->update($user, $validated, $request->user(), $validated['projects'] ?? []);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failed($e, 'ユーザーの更新に失敗しました');
        }

        return redirect()->route('staff.users.index')
            ->with('success', "ユーザー「{$user->name}」を更新しました。");
    }

    /**
     * ログイン案内メール(ログイン画面のURLとログイン方法)を再送する
     */
    public function resendLoginGuide(Request $request, User $user): RedirectResponse
    {
        $back = redirect()->route('staff.users.edit', $user);

        if (! $user->is_active) {
            return $back->with('error', '無効なユーザーにはログイン案内メールを送れません。先に「有効」にして更新してください。');
        }

        try {
            $sent = $this->users->resendLoginGuide($user, $request->user());
        } catch (Throwable $e) {
            return $this->failed($e, 'ログイン案内メールの再送に失敗しました');
        }

        if (! $sent) {
            return $back->with('error', '同じユーザーへのログイン案内メールの再送は、1時間に'.UserService::LOGIN_GUIDE_RESENDS_PER_HOUR
                .'回までです。約'.$this->users->loginGuideRetryMinutes($user).'分後に再送できます。');
        }

        return $back->with('success', "「{$user->name}」にログイン案内メールを送りました。");
    }

    /**
     * 認証コードの入力ミスによるログインのロックを解除する
     */
    public function unlock(Request $request, User $user): RedirectResponse
    {
        try {
            $this->loginCodes->unlock($user, $request->user());
        } catch (Throwable $e) {
            return $this->failed($e, 'ログインのロック解除に失敗しました');
        }

        return redirect()->route('staff.users.edit', $user)
            ->with('success', "ユーザー「{$user->name}」のログインのロックを解除しました。");
    }
}
