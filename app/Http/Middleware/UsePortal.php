<?php

namespace App\Http\Middleware;

use App\Support\Portal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ルートグループごとに「どのポータル(staff/customer)の画面か」を設定する。
 * デフォルトの認証ガードもポータルに合わせるので、$request->user() やポリシーは現在のポータルのユーザーで判定される。
 */
class UsePortal
{
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        Portal::set($portal);
        Auth::shouldUse($portal);
        View::share('portal', $portal);

        return $next($request);
    }
}
