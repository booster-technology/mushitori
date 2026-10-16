<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| テストの共通設定
|--------------------------------------------------------------------------
| Featureテストは毎回DBを作り直す(RefreshDatabase)
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// インストーラ・バックアップのテストは自前で一時ディレクトリにDB(SQLiteファイル)・.envを作るため RefreshDatabase を使わない
// (RefreshDatabase はテストをトランザクションで囲むが、SQLiteの VACUUM INTO 等はトランザクション内で実行できない)
pest()->extend(TestCase::class)
    ->in('Standalone');

/**
 * ユーザーのロールに対応するガード(staff/customer)でログインした状態にする
 */
function loginAs(User $user): TestCase
{
    return test()->actingAs($user, $user->role->value);
}

/**
 * 画面のHTMLが、厳しいCSPのもとでも動く書き方になっているか
 */
function expectCspCompatible(string $html, string $page): void
{
    // インラインのスクリプト(src の無い <script>)・<style>・style属性・javascript:・onclick等 を使っていない
    expect(preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $html))->toBe(0, "{$page}: インラインのスクリプト")
        ->and(preg_match('/<style[\s>]/i', $html))->toBe(0, "{$page}: <style>")
        ->and(preg_match('/\sstyle\s*=/i', $html))->toBe(0, "{$page}: style属性")
        ->and(preg_match('/javascript:/i', $html))->toBe(0, "{$page}: javascript:")
        ->and(preg_match('/\son[a-z]+\s*=\s*["\']/i', $html))->toBe(0, "{$page}: onclick等");

    // スクリプト・CSS・画像などは、すべて自分のサイトから読み込む(外部参照なし)
    preg_match_all('/<(?:script|img|iframe|source|video|audio|embed)\b[^>]*\ssrc="([^"]*)"|<link\b[^>]*\shref="([^"]*)"/i', $html, $matches);
    foreach (array_filter(array_merge($matches[1], $matches[2])) as $url) {
        $isExternal = preg_match('#^(https?:)?//#i', $url) && ! str_starts_with($url, url('/'));
        expect($isExternal)->toBeFalse("{$page}: 外部の読み込み {$url}");
    }
}
