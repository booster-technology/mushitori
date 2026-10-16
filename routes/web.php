<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\Staff\ProjectController;
use App\Http\Controllers\Staff\ProjectMemberController;
use App\Http\Controllers\Staff\TrashController;
use App\Http\Controllers\Staff\UserController;
use App\Services\TrashService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ルーティング
|--------------------------------------------------------------------------
| 社内メンバー用(/staff/...、ルート名 staff.*)と お客様用(/...、ルート名 customer.*)の2つのポータルがあり、
| 認証ガードもそれぞれ別(staff / customer)。同じブラウザで両方に同時ログインできる。
| 課題・コメントなど共通の画面は同じコントローラを両方のポータルに登録している。
| ビューでは route() ではなく portal_route('issues.show', ...) を使うと、現在のポータルのURLになる。
*/

// ログイン画面(メール+6桁コード)
$loginRoutes = function () {
    Route::get('/login', [LoginController::class, 'showEmailForm'])->name('login');
    Route::post('/login', [LoginController::class, 'sendCode'])->middleware('throttle:login-code-send')->name('login.send');
    Route::get('/login/code', [LoginController::class, 'showCodeForm'])->name('login.code');
    Route::post('/login/code', [LoginController::class, 'verifyCode'])->middleware('throttle:login-code-verify')->name('login.verify');
    Route::post('/login/resend', [LoginController::class, 'resend'])->middleware('throttle:login-code-send')->name('login.resend');
};

// 両ポータル共通の画面(権限の違いはポリシーで判定)
$sharedRoutes = function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // 使い方(社内メンバーには社内向け、お客様にはお客様向けの説明)
    Route::get('/help', [HelpController::class, 'show'])->name('help');

    // 課題(create は {issue} より前に定義する)
    // 添付ファイル付きの投稿は画像処理でサーバーに負荷がかかるため、1人あたりの回数を制限する(throttle:posts)
    Route::get('/projects/{project}/issues', [IssueController::class, 'index'])->name('issues.index');
    Route::get('/projects/{project}/issues/create', [IssueController::class, 'create'])->name('issues.create');
    Route::post('/projects/{project}/issues', [IssueController::class, 'store'])->middleware('throttle:posts')->name('issues.store');
    Route::get('/issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
    Route::get('/issues/{issue}/edit', [IssueController::class, 'edit'])->name('issues.edit');
    Route::put('/issues/{issue}', [IssueController::class, 'update'])->middleware('throttle:posts')->name('issues.update');
    Route::delete('/issues/{issue}', [IssueController::class, 'destroy'])->name('issues.destroy');

    // コメント
    Route::post('/issues/{issue}/comments', [CommentController::class, 'store'])->middleware('throttle:posts')->name('comments.store');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

    // 添付ファイル
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])->name('attachments.thumbnail');
};

// ===== 社内メンバー用 =====
Route::prefix(config('app.staff_path'))
    ->name('staff.')
    ->middleware('portal:staff')
    ->group(function () use ($loginRoutes, $sharedRoutes) {
        Route::middleware('guest:staff')->group($loginRoutes);

        Route::middleware(['auth:staff', 'active:staff'])->group(function () use ($sharedRoutes) {
            $sharedRoutes();

            // お客様向けの使い方(お客様への案内のために、社内メンバーも内容を確認できるように)
            Route::get('/help/customer', [HelpController::class, 'customer'])->name('help.customer');

            // プロジェクト管理
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
            Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
            Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
            Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
            Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');

            // ユーザー管理
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');

            // ゴミ箱(削除したデータの確認・元に戻す・完全に削除)
            Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
            Route::post('/trash/{type}/{id}/restore', [TrashController::class, 'restore'])
                ->whereIn('type', array_keys(TrashService::TYPES))->whereNumber('id')->name('trash.restore');
            Route::delete('/trash/{type}/{id}', [TrashController::class, 'destroy'])
                ->whereIn('type', array_keys(TrashService::TYPES))->whereNumber('id')->name('trash.destroy');
        });
    });

// ===== お客様用 =====
Route::name('customer.')
    ->middleware('portal:customer')
    ->group(function () use ($loginRoutes, $sharedRoutes) {
        Route::middleware('guest:customer')->group($loginRoutes);

        Route::middleware(['auth:customer', 'active:customer'])->group($sharedRoutes);
    });
