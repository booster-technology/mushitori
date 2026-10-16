<?php

use App\Models\User;
use App\Services\InstallerService;

/*
| 使い方(ヘルプ)の画面のテスト
*/

test('社内メンバーには社内向けの使い方が表示され、お客様向けの使い方も確認できる', function () {
    $staff = User::factory()->staff()->create();

    loginAs($staff);
    $this->get('/staff')->assertOk()->assertSee('href="'.route('staff.help').'"', false); // ヘッダーの「使い方」
    $this->get('/staff/help')->assertOk()
        ->assertSee('使い方（社内メンバー向け）')
        ->assertSee('プロジェクトを作成')
        ->assertSee(route('customer.login'))
        ->assertSee(route('staff.help.customer'));

    $this->get('/staff/help/customer')->assertOk()
        ->assertSee('これは<strong>お客様向けの使い方</strong>です', false)
        ->assertSee('課題を登録する');
});

test('お客様にはお客様向けの使い方が表示され、社内用のURLや管理機能の説明は出ない', function () {
    $customer = User::factory()->customer()->create();

    loginAs($customer);
    $this->get('/')->assertOk()->assertSee('href="'.route('customer.help').'"', false); // ヘッダーの「使い方」
    $this->get('/help')->assertOk()
        ->assertSee('課題を登録する')
        ->assertSee('スクリーンショットの撮り方')
        ->assertSee(route('customer.login'))
        ->assertDontSee('/staff/')
        ->assertDontSee('ユーザー管理')
        ->assertDontSee('お客様向けの使い方です');
});

test('使い方はログインしないと見られず、お客様は社内向けの使い方を見られない', function () {
    $this->get('/help')->assertRedirect('/login');
    $this->get('/staff/help')->assertRedirect('/staff/login');

    loginAs(User::factory()->customer()->create());
    $this->get('/staff/help')->assertRedirect('/staff/login');
    $this->get('/staff/help/customer')->assertRedirect('/staff/login');
});

test('社内用画面のURLに「help」は使えない(お客様用の使い方のURLと重なるため)', function () {
    expect(InstallerService::validateStaffPath('help'))->not->toBeNull();
});
