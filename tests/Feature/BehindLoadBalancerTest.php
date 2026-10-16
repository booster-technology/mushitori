<?php

use App\Providers\AppServiceProvider;
use App\Services\InstallerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| ロードバランサー(AWS ALB)の後ろで動かす場合のテスト。
| ALB(10.0.1.23)がHTTPSを終端し、X-Forwarded-Proto / X-Forwarded-For を付けてHTTPで転送してくる状況を再現する
*/

beforeEach(function () {
    // 接続元のIPアドレスを確認するためのテスト用ルート
    Route::get('/__test/client-ip', fn (Request $request) => $request->ip().' '.($request->secure() ? 'https' : 'http'));
});

/**
 * ALB経由のリクエストを再現する(利用者 203.0.113.7 → ALB 10.0.1.23 → このサーバー)
 */
function viaAlb(array $extraHeaders = [])
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => '10.0.1.23'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '203.0.113.7'] + $extraHeaders);
}

test('TRUSTED_PROXIES を設定すると、ALBの後ろでもフォームの送信先が https になり、利用者のIPを正しく扱う', function () {
    // インストーラの「ロードバランサーの後ろで動かす」で設定される値
    config(['trustedproxy.proxies' => InstallerService::PRIVATE_NETWORKS]);

    $html = viaAlb()->get('/login')->assertOk()->getContent();
    preg_match('/<form method="POST" action="([^"]+)"/', $html, $m);
    expect($m[1])->toStartWith('https://');

    viaAlb()->get('/__test/client-ip')->assertSee('203.0.113.7 https');
});

test('信頼するプロキシをVPCのCIDRで指定することもできる', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/16']);

    viaAlb()->get('/__test/client-ip')->assertSee('203.0.113.7 https');
});

test('利用者が X-Forwarded-For を偽装しても、ALBが付け足した本当のIPアドレスを使う(ログイン試行回数の制限をすり抜けられないように)', function () {
    config(['trustedproxy.proxies' => InstallerService::PRIVATE_NETWORKS]);

    // 利用者(203.0.113.7)が「X-Forwarded-For: 6.6.6.6」を付けて送ると、ALBは末尾に本当のIPを付け足して転送してくる
    viaAlb(['X-Forwarded-For' => '6.6.6.6, 203.0.113.7'])->get('/__test/client-ip')->assertSee('203.0.113.7 https');
    // プライベートIPを偽装しても同じ
    viaAlb(['X-Forwarded-For' => '10.9.9.9, 203.0.113.7'])->get('/__test/client-ip')->assertSee('203.0.113.7 https');
});

test('X-Forwarded-Host は信頼しない(偽のホスト名でリンクを書き換えられないように)', function () {
    config(['trustedproxy.proxies' => InstallerService::PRIVATE_NETWORKS]);

    $html = viaAlb(['X-Forwarded-Host' => 'evil.example'])->get('/login')->getContent();
    expect($html)->not->toContain('evil.example');
});

test('TRUSTED_PROXIES が空なら、利用者が送ってきた X-Forwarded-* は無視する(なりすまし対策)', function () {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '1.2.3.4'])
        ->get('/__test/client-ip')
        ->assertSee('198.51.100.9 http');
});

test('本番では、プロキシの設定に関わらず画面のURLを APP_URL(https)基準で作る', function () {
    // 本番環境・APP_URL が https の設定で、起動処理をやり直す
    $this->app['env'] = 'production';
    config(['app.url' => 'https://issues.example.test', 'trustedproxy.proxies' => null]);
    (new AppServiceProvider($this->app))->boot();

    // TRUSTED_PROXIES を設定し忘れていても、HTTPで届いたリクエストのフォームの送信先は https
    $html = $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.23'])->get('/login')->getContent();
    preg_match('/<form method="POST" action="([^"]+)"/', $html, $m);
    expect($m[1])->toBe('https://issues.example.test/login')
        // CSS・JSなども https(混在コンテンツで読み込みがブロックされないように)
        ->and($html)->toContain('href="https://issues.example.test/vendor/bootstrap/css/bootstrap.min.css');

    // Hostヘッダーを偽装されてもリンクは APP_URL のまま
    $html = $this->withHeaders(['Host' => 'evil.example'])->get('/login')->getContent();
    expect($html)->not->toContain('evil.example');
});
