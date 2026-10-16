<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中のユーザーが有効かつ想定したロールかを確認する。
 * 無効化された場合などは、このポータルのガードだけをログアウトさせる
 * (セッション全体は破棄しないので、同じブラウザの別ポータルのログイン状態は維持される)。
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = Auth::guard($role)->user();

        if ($user === null || ! $user->is_active || $user->role->value !== $role) {
            Auth::guard($role)->logout();

            return redirect()->route($role.'.login')
                ->with('error', 'このアカウントは現在ご利用いただけません。');
        }

        return $next($request);
    }
}
