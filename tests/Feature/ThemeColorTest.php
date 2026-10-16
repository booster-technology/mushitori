<?php

use App\Models\User;
use App\Support\ThemeColor;

test('カラーコードは表記ゆれを吸収し、不正な値は受け付けない', function () {
    expect(ThemeColor::normalize('#0d223a'))->toBe('#0D223A')
        ->and(ThemeColor::normalize('0D223A'))->toBe('#0D223A')
        ->and(ThemeColor::normalize('#abc'))->toBe('#AABBCC')
        ->and(ThemeColor::normalize('red'))->toBeNull()
        ->and(ThemeColor::normalize('#12345'))->toBeNull()
        ->and(ThemeColor::normalize('#0D223A;}body{display:none'))->toBeNull()
        ->and(ThemeColor::resolve('green'))->toBe('#1F8A70')
        ->and(ThemeColor::resolve('#123abc'))->toBe('#123ABC');
});

test('暗い色には白文字、明るい色には黒文字を自動で選ぶ', function (string $color, string $expectedText) {
    $theme = new ThemeColor($color);

    expect($theme->onColor())->toBe($expectedText);
    // ボタン等の文字は十分読める(黒/白のうち良い方を選ぶので3以上は必ず確保される)
    expect(ThemeColor::contrast(ThemeColor::fromHex($color), ThemeColor::fromHex($theme->onColor())))->toBeGreaterThan(3.0);
})->with([
    '濃紺(標準)' => ['#0D223A', '#FFFFFF'],
    '赤' => ['#B8332E', '#FFFFFF'],
    '黄色' => ['#FFD54F', '#212529'],
    '白' => ['#FFFFFF', '#212529'],
]);

test('リンク色はどのテーマカラーでも白背景で読める濃さになる(コントラスト比4.5以上)', function (string $color) {
    $link = ThemeColor::fromHex((new ThemeColor($color))->link());

    expect(ThemeColor::contrast($link, [255, 255, 255]))->toBeGreaterThanOrEqual(4.5);
})->with(['#0D223A', '#1F8A70', '#FFD54F', '#FFFFFF', '#00FFFF', '#000000']);

test('標準は #0D223A で、不正な設定値でも標準色にフォールバックする', function () {
    expect(ThemeColor::DEFAULT)->toBe('#0D223A');

    config(['app.theme_color' => 'not-a-color']);
    expect(ThemeColor::fromConfig()->hex())->toBe('#0D223A');
});

test('設定したテーマカラーが全画面のCSS変数(/theme.css)に反映され、不正な値は埋め込まれない', function () {
    // 画面は /theme.css を色ごとに異なるURL(?v=)で読み込む(色を変えるとブラウザのキャッシュも切り替わる)
    config(['app.theme_color' => '#B8332E']);
    $red = ThemeColor::fromConfig()->version();
    $this->get('/login')->assertOk()->assertSee(route('theme.css', ['v' => $red]), false);
    $this->get('/theme.css')->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
        ->assertSee('--mt-primary:#B8332E;', false);

    config(['app.theme_color' => '#000;}</style><script>alert(1)</script>']);
    expect(ThemeColor::fromConfig()->version())->not->toBe($red);
    $this->get('/theme.css')->assertOk()
        ->assertSee('--mt-primary:#0D223A;', false)
        ->assertDontSee('<script>', false);
    $this->get('/login')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

test('明るいテーマカラーの場合は、お客様用ヘッダーの文字を黒にする', function () {
    $customer = User::factory()->customer()->create();
    loginAs($customer);

    config(['app.theme_color' => '#FFD54F']);
    $this->get('/')->assertOk()
        ->assertSee('data-bs-theme="light"', false)
        ->assertSee('btn-outline-dark', false);

    config(['app.theme_color' => '#0D223A']);
    $this->get('/')->assertSee('data-bs-theme="dark"', false);
});
