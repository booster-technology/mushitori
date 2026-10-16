<?php

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\IssueService;
use App\Services\LoginCodeService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
| 例外・異常時にログが出力されることのテスト
*/

beforeEach(function () {
    $this->customer = User::factory()->customer()->create();
    $this->project = Project::factory()->create(['key' => 'WEB']);
    $this->project->members()->attach($this->customer->id);
});

test('画面操作で想定外のエラーが起きたら、例外とどの画面で誰が操作したかをログに残し、画面には詳細を出さない', function () {
    Mail::fake();
    Log::spy();
    $this->mock(IssueService::class, fn ($mock) => $mock->shouldReceive('create')->andThrow(new RuntimeException('DBに接続できません')));
    loginAs($this->customer);

    $this->from('/projects/WEB/issues/create')
        ->post('/projects/WEB/issues', ['type' => 'bug', 'title' => 'x', 'priority' => 'normal'])
        ->assertRedirect('/projects/WEB/issues/create')
        ->assertSessionHas('error', '処理中に問題が発生しました。時間をおいて再度お試しください。');

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
        return $message === '課題の登録に失敗しました'
            && $context['exception'] instanceof RuntimeException
            && $context['user_id'] === $this->customer->id
            && $context['route'] === 'customer.issues.store'
            && $context['method'] === 'POST'
            && str_contains($context['url'], '/projects/WEB/issues');
    })->once();
});

test('try-catch していない場所の例外も記録され、画面には技術的な詳細を出さない(500)', function () {
    Exceptions::fake();
    config(['app.debug' => false]);
    $this->mock(DashboardService::class, fn ($mock) => $mock->shouldReceive('forUser')->andThrow(new RuntimeException('秘密の内部情報')));
    loginAs($this->customer);

    $this->get('/')->assertStatus(500)
        ->assertSee('問題が発生しました')
        ->assertDontSee('秘密の内部情報');

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '秘密の内部情報');
});

test('通知メールの送信に失敗しても課題は登録され、失敗はログに残る', function () {
    Log::spy();
    User::factory()->staff()->create();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTPサーバーに接続できません'));
    loginAs($this->customer);

    $this->post('/projects/WEB/issues', ['type' => 'bug', 'title' => '通知失敗テスト', 'priority' => 'normal'])
        ->assertRedirect('/issues/WEB-1');

    expect(Issue::where('title', '通知失敗テスト')->exists())->toBeTrue();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === '通知メールの送信に失敗しました'
        && $context['exception'] instanceof RuntimeException)->atLeast()->once();
});

test('添付ファイルの実体が無い場合は、404にした上で異常としてログに残す', function () {
    Log::spy();
    $issue = Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);
    $attachment = Attachment::create([
        'issue_id' => $issue->id, 'user_id' => $this->customer->id, 'original_name' => 'lost.png',
        'path' => 'attachments/missing/lost.png', 'mime_type' => 'image/png', 'size' => 10,
    ]);
    loginAs($this->customer);

    $this->get("/attachments/{$attachment->id}")->assertNotFound();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === '添付ファイルの実体が見つかりません'
        && $context['attachment_id'] === $attachment->id)->once();
});

test('認証コードの入力ミスでアカウントが一時ロックされたら記録する(コード・メールアドレスはログに出さない)', function () {
    Mail::fake();
    Log::spy();
    $service = app(LoginCodeService::class);

    // コードを再発行しながら、合計10回(=上限)間違える
    for ($round = 0; $round < 2; $round++) {
        $service->send($this->customer->email, UserRole::Customer);
        for ($i = 0; $i < 5; $i++) {
            $service->verify($this->customer->email, UserRole::Customer, '000000');
        }
    }

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        return str_contains($message, '一時的にログインをロックしました')
            && $context['user_id'] === $this->customer->id
            && ! in_array($this->customer->email, $context, true);
    })->once();
});
