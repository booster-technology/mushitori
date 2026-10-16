<?php

use App\Models\User;

/*
| ロゴの表示(サイト名が「ムシトリ」ならロゴ画像、独自のサイト名なら アイコン + サイト名)
*/

test('サイト名が「ムシトリ」なら、ヘッダーは白文字のロゴ、ログイン画面は通常のロゴを表示する', function () {
    config(['app.name' => 'ムシトリ']);

    $this->get('/login')->assertOk()
        ->assertSee('images/logo.png', false)
        ->assertSee('favicon.ico', false)
        ->assertSee('apple-touch-icon.png', false);

    loginAs(User::factory()->customer()->create());
    $this->get('/')->assertOk()->assertSee('images/logo-white.png', false);
});

test('明るいテーマカラーのヘッダーでは、通常(濃い文字)のロゴを表示する', function () {
    config(['app.name' => 'ムシトリ', 'app.theme_color' => '#FFD54F']);
    loginAs(User::factory()->customer()->create());

    $this->get('/')->assertOk()
        ->assertSee('images/logo.png', false)
        ->assertDontSee('images/logo-white.png', false);
});

test('独自のサイト名にしている場合は、ロゴ画像ではなく網のアイコンとサイト名を表示する', function () {
    config(['app.name' => '株式会社サンプル 課題管理']);

    $this->get('/login')->assertOk()
        ->assertSee('株式会社サンプル 課題管理')
        ->assertSee('images/icon-192.png', false)
        ->assertDontSee('images/logo.png', false);
});
