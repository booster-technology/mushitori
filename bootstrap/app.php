<?php

use App\Http\Controllers\ThemeController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UsePortal;
use App\Support\Portal;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // テーマカラーのCSS。セッション等は不要なため web ミドルウェアの外に置く(エラー画面などでも使える)
        then: function () {
            Route::get('/theme.css', ThemeController::class)->name('theme.css');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'portal' => UsePortal::class,
            'active' => EnsureActiveUser::class,
        ]);

        // すべての応答(存在しないURLの404なども含む)にセキュリティ関連のヘッダーを付ける
        $middleware->append(SecurityHeaders::class);

        // ロードバランサー(ALB等)の後ろで動かす場合: 信頼するプロキシは config/trustedproxy.php(.env の TRUSTED_PROXIES)。
        // 信頼するヘッダーはALBが付ける X-Forwarded-For / Port / Proto だけにする(X-Forwarded-Host は偽装できるため信頼しない)
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_AWS_ELB);

        // 認証ミドルウェアは優先度リストで先に実行されるため、ポータル設定をさらにその前に割り込ませる
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: UsePortal::class);

        // 未ログイン時はアクセスしたポータルのログイン画面へ、ログイン済みでログイン画面に来たらホームへ
        $middleware->redirectGuestsTo(fn (Request $request) => route(Portal::fromRequest($request).'.login'));
        $middleware->redirectUsersTo(fn (Request $request) => route(Portal::fromRequest($request).'.home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 入力エラーで画面に戻すとき、パスワード類は入力欄に戻さない(インストーラのDB・SMTPのパスワードなど)
        $exceptions->dontFlash(['db_password', 'mail_password']);

        // サーバーの上限(post_max_size)を超えるファイルが送られた場合(PostTooLargeException)は
        // セッション開始前に発生するため、元の画面には戻さず errors/413.blade.php を表示する
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
