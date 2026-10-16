<?php

namespace App\Providers;

use App\Services\LoginCodeService;
use App\Support\Portal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ページングはBootstrap 5のデザインで表示する
        Paginator::useBootstrapFive();

        // URLパラメータの形式を固定する(プロジェクトキー: WEB、課題キー: WEB-12)
        Route::pattern('project', '[A-Z][A-Z0-9_]*');
        Route::pattern('issue', '[A-Z][A-Z0-9_]*-[0-9]+');
        Route::pattern('comment', '[0-9]+');
        Route::pattern('attachment', '[0-9]+');
        Route::pattern('user', '[0-9]+');

        $this->configureRateLimiting();
        $this->configureUrls();
    }

    /**
     * 本番では、画面のリンクやフォームの送信先などのURLを常に APP_URL(例: https://issues.example.com)を基準に作る。
     * - ロードバランサーでHTTPSを終端している場合でも、フォームの送信先が http:// にならない
     * - リクエストのHostヘッダーを偽装されても、メール内のリンクなどが他のドメインにならない
     * お試し・開発環境では、開いているURL(localhost / 127.0.0.1 など)のまま動かす
     */
    private function configureUrls(): void
    {
        $url = (string) config('app.url');
        if (! $this->app->isProduction() || ! preg_match('#^https?://#', $url)) {
            return;
        }

        URL::forceRootUrl(rtrim($url, '/'));
        URL::forceScheme((string) parse_url($url, PHP_URL_SCHEME));
    }

    /**
     * 認証コードの送信・検証のレート制限(総当たり・メール爆撃対策)
     */
    private function configureRateLimiting(): void
    {
        $tooMany = fn () => back()->with('error', '試行回数が多すぎます。しばらく時間をおいてから再度お試しください。');

        RateLimiter::for('login-code-send', function (Request $request) use ($tooMany) {
            $email = $request->input('email') ?? $request->session()->get('login.'.Portal::fromRequest($request).'.email');
            $email = is_string($email) ? LoginCodeService::normalizeEmail($email) : '';

            return [
                Limit::perMinute(5)->by('send:'.$email.'|'.$request->ip())->response($tooMany),
                Limit::perMinute(20)->by('send-ip:'.$request->ip())->response($tooMany),
                // 宛先ごとの上限(接続元に関係なく1時間10回)は LoginCodeService::send() で数える(ロック解除でリセットできるように)
            ];
        });

        RateLimiter::for('login-code-verify', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)->by('verify:'.$request->ip())->response($tooMany);
        });

        // 課題・コメントの投稿(添付画像の縮小・サムネイル作成でサーバーに負荷がかかる)。通常の利用では届かない回数にしている
        RateLimiter::for('posts', function (Request $request) use ($tooMany) {
            return Limit::perMinute(20)->by('posts:'.portal().':'.($request->user()?->id ?? $request->ip()))->response($tooMany);
        });
    }
}
