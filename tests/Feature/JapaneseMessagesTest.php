<?php

use App\Mail\AccountCreatedMail;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| エラーメッセージ・エラー画面がすべて日本語になっていることのテスト
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
});

/**
 * メッセージが日本語か(英語の標準メッセージや、項目名が英語のまま残っていないか)
 */
function expectJapaneseMessage(string $message): void
{
    expect($message)
        ->toMatch('/[ぁ-んァ-ヶ一-龠]/u')                   // 日本語を含む
        ->not->toMatch('/\b(the|field|must|is|invalid)\b/i') // 英語の標準メッセージではない
        ->not->toMatch('/[a-z]+_[a-z]+|\.\d+|:[a-z]+/');     // 項目名(assignee_id・attachments.0)や :attribute が残っていない
}

test('Laravelのすべての入力チェックのルールに日本語のメッセージがある', function () {
    $flatten = function (array $messages, string $prefix = '') use (&$flatten): array {
        $result = [];
        foreach ($messages as $key => $value) {
            if (in_array($key, ['custom', 'attributes'], true)) {
                continue;
            }
            $result += is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key => $value];
        }

        return $result;
    };
    $english = $flatten(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
    $japanese = $flatten(require lang_path('ja/validation.php'));

    // Laravelの更新で新しいルールが増えたら、ここで気付けるようにする
    expect(array_keys(array_diff_key($english, $japanese)))->toBe([]);
    foreach ($japanese as $message) {
        expect($message)->toMatch('/[ぁ-んァ-ヶ一-龠]/u');
    }
});

test('各画面の入力エラーは日本語で表示される', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $project = Project::factory()->create(['key' => 'WEB']);
    $project->members()->attach([$staff->id, $customer->id]);
    Issue::factory()->create(['project_id' => $project->id, 'reporter_id' => $customer->id]);

    $messages = [];
    $collect = function () use (&$messages) {
        foreach (session('errors')?->all() ?? [] as $message) {
            $messages[] = $message;
        }
    };

    // ログイン
    $this->post('/login', ['email' => 'メールではない'])->assertSessionHasErrors('email');
    $collect();
    $this->post('/login', ['email' => 'c@example.com']);
    $this->post('/login/code', ['code' => 'abc'])->assertSessionHasErrors('code');
    $collect();

    // 課題の登録(お客様)
    loginAs($customer);
    $this->post('/projects/WEB/issues', [
        'type' => 'unknown', 'title' => '', 'priority' => 'x',
        'attachments' => [UploadedFile::fake()->create('virus.exe', 1)],
    ])->assertSessionHasErrors(['type', 'title', 'priority', 'attachments.0']);
    $collect();
    $this->post('/issues/WEB-1/comments', [])->assertSessionHasErrors();
    $collect();

    // 社内メンバーの操作
    loginAs($staff);
    $this->post('/staff/issues/WEB-1/comments', ['body' => 'x', 'assignee_id' => $customer->id, 'due_date' => '明日'])
        ->assertSessionHasErrors(['assignee_id', 'due_date']);
    $collect();
    $this->post('/staff/projects', ['key' => 'web key', 'name' => ''])->assertSessionHasErrors(['key', 'name']);
    $collect();
    $this->post('/staff/users', ['role' => 'admin', 'name' => '', 'email' => 'x'])->assertSessionHasErrors(['role', 'name', 'email']);
    $collect();
    $this->post('/staff/projects/WEB/members', ['user_id' => 99999])->assertSessionHasErrors('user_id');
    $collect();

    expect($messages)->not->toBeEmpty();
    foreach ($messages as $message) {
        expectJapaneseMessage($message);
    }
});

test('個別の画面を用意していないエラー(405・502など)も、日本語の画面で表示される', function () {
    Route::get('/__test/error/{status}', fn (int $status) => abort($status));

    // POST専用のURLを開いた(405)
    $this->get('/logout')->assertStatus(405)->assertSee('ページを表示できません')->assertDontSee('Method Not Allowed');

    foreach ([401 => 'ログインが必要です', 402 => 'ご利用いただけません', 400 => 'ページを表示できません', 502 => '問題が発生しました'] as $status => $title) {
        $this->get("/__test/error/{$status}")->assertStatus($status)->assertSee($title)->assertSee((string) $status);
    }
});

test('メールのフッターも日本語になっている', function () {
    $html = (new AccountCreatedMail(User::factory()->customer()->make()))->render();

    expect($html)->toContain('無断転載を禁じます')->not->toContain('All rights reserved');
});
