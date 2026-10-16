<?php

use App\Enums\UserRole;
use App\Mail\IssueCommentedMail;
use App\Mail\LoginCodeMail;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\InstallerService;
use App\Services\LoginCodeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| セキュリティチェックで見つかった問題の再発防止テスト
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    $this->staff = User::factory()->staff()->create();
    $this->customer = User::factory()->customer()->create();
    $this->project = Project::factory()->create(['key' => 'WEB']);
    $this->project->members()->attach([$this->staff->id, $this->customer->id]);

    // 別プロジェクトとそのお客様(他社)
    $this->otherCustomer = User::factory()->customer()->create();
    $this->otherProject = Project::factory()->create(['key' => 'OTH']);
    $this->otherProject->members()->attach($this->otherCustomer->id);
});

/**
 * 課題を登録する(添付ファイル付き)
 */
function postIssueWith(array $attachments): TestResponse
{
    return test()->post('/projects/WEB/issues', [
        'type' => 'bug', 'title' => '画面が崩れる', 'priority' => 'normal', 'attachments' => $attachments,
    ]);
}

/**
 * ヘッダーだけのPNG(画素数だけ大きく、ファイルは数十バイト)を作る
 */
function pngHeaderOnly(int $width, int $height): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IEND', '');
}

// ===== 権限 =====

test('お客様が見られないプロジェクトの課題・コメントを削除しようとしても404(存在を知らせない)', function () {
    $issue = Issue::factory()->create(['project_id' => $this->otherProject->id, 'reporter_id' => $this->otherCustomer->id]);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'user_id' => $this->otherCustomer->id]);

    loginAs($this->customer);
    $this->delete('/issues/OTH-1')->assertNotFound();
    $this->delete("/comments/{$comment->id}")->assertNotFound();

    // 見られるプロジェクトの課題は「権限なし」のまま(お客様は課題を削除できない)
    Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);
    $this->delete('/issues/WEB-1')->assertForbidden();

    expect(Issue::count())->toBe(2)->and(Comment::count())->toBe(1);
});

test('URLのパラメーターや入力に配列・空文字を送ってもエラー(500)にならない', function () {
    Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);

    loginAs($this->customer);
    $this->get('/projects/WEB/issues?q[]=x&status[]=a&type[]=b&priority[]=c&assignee[]=d&sort[]=e')->assertOk();
    $this->get('/projects/WEB/issues/create?type[]=x')->assertOk();
    $this->put('/issues/WEB-1', ['type' => 'bug', 'title' => '件名', 'remove_attachments' => ''])->assertRedirect('/issues/WEB-1');

    loginAs($this->staff);
    $this->get('/staff/users?role[]=x&q[]=y')->assertOk();
});

test('お客様の課題一覧の「担当者」の選択肢には、このプロジェクトの課題の担当者だけが表示される', function () {
    $assignee = User::factory()->staff()->create(['name' => '担当 次郎']);
    User::factory()->staff()->create(['name' => '無関係 三郎']);
    Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id, 'assignee_id' => $assignee->id]);

    loginAs($this->customer);
    $this->get('/projects/WEB/issues')->assertOk()->assertSee('担当 次郎')->assertDontSee('無関係 三郎');

    // 社内メンバーには全員が表示される
    loginAs($this->staff);
    $this->get('/staff/projects/WEB/issues')->assertOk()->assertSee('無関係 三郎');
});

test('通知メールのリンクは、アクセスしてきたホスト名ではなく APP_URL を基準にする(偽サイトへの誘導対策)', function () {
    config(['app.url' => 'https://issues.example.test']);
    Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);

    // Hostヘッダーを偽装してコメントする
    loginAs($this->customer);
    $this->post('http://evil.example/issues/WEB-1/comments', ['body' => '確認お願いします'])->assertRedirect();

    Mail::assertSent(IssueCommentedMail::class, function (IssueCommentedMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'https://issues.example.test/staff/issues/WEB-1') && ! str_contains($html, 'evil.example');
    });
});

test('ユーザーを無効化すると「ログインしたままにする」が取り消される(有効に戻しても自動ログインは復活しない)', function () {
    $this->customer->forceFill(['remember_token' => 'remember-me-token'])->save();

    loginAs($this->staff);
    $this->put("/staff/users/{$this->customer->id}", [
        'name' => $this->customer->name, 'email' => $this->customer->email, 'role' => 'customer', 'is_active' => '0',
    ])->assertRedirect();

    expect($this->customer->fresh()->remember_token)->toBeNull();
});

test('課題・コメントの投稿は1人あたり1分に20回まで(画像処理によるサーバーへの負荷対策)', function () {
    Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);

    loginAs($this->customer);
    for ($i = 0; $i < 20; $i++) {
        $this->post('/issues/WEB-1/comments', ['body' => "コメント{$i}"])->assertSessionHasNoErrors();
    }
    $this->post('/issues/WEB-1/comments', ['body' => '21回目'])->assertSessionHas('error');

    expect(Comment::count())->toBe(20);
});

// ===== ログイン =====

test('未登録のメールアドレスでも、入力ミスが続けば同じロックの表示になる(登録の有無を推測させない)', function () {
    $this->post('/login', ['email' => 'nobody@example.com']);

    for ($i = 0; $i < 10; $i++) {
        // IPアドレス単位の1分あたりの制限に掛からないよう、時間を進める
        if ($i % 5 === 0) {
            $this->travel(2)->minutes();
        }
        $this->post('/login/code', ['code' => '123456'])->assertSessionHasErrors('code');
    }

    $this->travel(2)->minutes();
    $this->post('/login/code', ['code' => '123456'])
        ->assertSessionHasErrors(['code' => '認証コードの入力ミスが続いたため、一時的にログインできません。1時間ほどおいてから再度お試しください。']);
});

test('同じ宛先への認証コードの送信は、接続元を変えても1時間に10回まで(メール爆撃対策)。ロックの解除でリセットされる', function () {
    $customer = User::factory()->customer()->create(['email' => 'c@example.com']);

    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
            ->post('/login', ['email' => 'c@example.com'])
            ->assertRedirect('/login/code');
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->post('/login', ['email' => 'c@example.com'])
        ->assertSessionHas('error');
    Mail::assertSentCount(10);

    // 社内メンバーがロックを解除すると、すぐに再び受け取れる
    app(LoginCodeService::class)->unlock($customer);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->post('/login', ['email' => 'c@example.com'])
        ->assertRedirect('/login/code');
    Mail::assertSentCount(11);
});

/**
 * 認証コードを10回間違えて、ログインをロックさせる
 */
function lockOutLogin(string $prefix, string $email): void
{
    test()->post($prefix.'/login', ['email' => $email]);
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 10; $i++) {
        // IPアドレス単位の1分あたりの制限に掛からないよう、時間を進める
        if ($i % 5 === 0) {
            test()->travel(2)->minutes();
        }
        test()->post($prefix.'/login/code', ['code' => $wrong]);
    }
}

test('ロックされたユーザーは、社内メンバーがユーザー管理画面から解除でき、本人はすぐにログインできる', function () {
    $customer = User::factory()->customer()->create(['email' => 'locked@example.com', 'name' => 'ロック 花子']);
    lockOutLogin('', 'locked@example.com');
    $loginCodes = app(LoginCodeService::class);
    expect($loginCodes->lockedMinutes($customer))->toBeGreaterThan(0);

    // 一覧に「ロック中」、編集画面に解除ボタンが表示される
    loginAs($this->staff);
    $this->get('/staff/users')->assertOk()->assertSee('ロック中');
    $this->get("/staff/users/{$customer->id}/edit")->assertOk()
        ->assertSee('ログインがロックされています')
        ->assertSee('ロックを解除');

    $this->post("/staff/users/{$customer->id}/unlock")
        ->assertRedirect("/staff/users/{$customer->id}/edit")
        ->assertSessionHas('success');
    $this->get("/staff/users/{$customer->id}/edit")->assertDontSee('ログインがロックされています');
    $this->get('/staff/users')->assertDontSee('ロック中');
    expect($loginCodes->lockedMinutes($customer))->toBeNull();

    // 本人は待たずにログインできる
    Mail::fake();
    $this->travel(2)->minutes();
    $this->post('/login', ['email' => 'locked@example.com'])->assertRedirect('/login/code');
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });
    $this->post('/login/code', ['code' => $code])->assertRedirect('/');
});

test('ロックの解除は社内メンバーだけができる', function () {
    loginAs($this->customer);
    $this->post("/staff/users/{$this->otherCustomer->id}/unlock")->assertRedirect('/staff/login');
});

test('ロック解除のコマンド(app:unlock-login)は、ロールなどを変えずにロックだけを解除する', function () {
    $customer = User::factory()->customer()->create(['email' => 'locked@example.com']);
    lockOutLogin('', 'locked@example.com');

    $this->artisan('app:unlock-login', ['email' => 'LOCKED@example.com'])->assertSuccessful();

    expect(app(LoginCodeService::class)->lockedMinutes($customer))->toBeNull()
        ->and($customer->fresh()->role)->toBe(UserRole::Customer);

    $this->artisan('app:unlock-login', ['email' => 'nobody@example.com'])->assertFailed();
});

test('社内メンバーの復旧コマンド(app:create-staff)でも、入力ミスによるロックが解除される', function () {
    User::factory()->staff()->create(['email' => 's@example.com']);
    lockOutLogin('/staff', 's@example.com');
    $loginCodes = app(LoginCodeService::class);
    expect($loginCodes->tooManyFailures('s@example.com', UserRole::Staff))->toBeTrue();

    $this->artisan('app:create-staff', ['email' => 's@example.com'])->assertSuccessful();

    expect($loginCodes->tooManyFailures('s@example.com', UserRole::Staff))->toBeFalse();
});

test('app:create-staff にお客様のメールアドレスを指定しても、確認なしに社内メンバーには変えない', function () {
    $this->artisan('app:create-staff', ['email' => $this->customer->email])
        ->expectsConfirmation("{$this->customer->email} はお客様として登録されています。社内メンバーに変更しますか？（全プロジェクトの課題を見られるようになります）", 'no')
        ->assertFailed();

    expect($this->customer->fresh()->role)->toBe(UserRole::Customer);
});

// ===== 添付ファイル =====

test('中身が空のセグメントを含むJPEGも添付でき、エラーにならない', function () {
    $image = imagecreatetruecolor(120, 80);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    // 画像の先頭(SOI)の直後に、長さ2(中身なし)のコメントセグメントを入れる
    $jpeg = "\xFF\xD8\xFF\xFE\x00\x02".substr($jpeg, 2);

    loginAs($this->customer);
    postIssueWith([UploadedFile::fake()->createWithContent('photo.jpg', $jpeg)])->assertRedirect('/issues/WEB-1');

    $attachment = Attachment::firstOrFail();
    expect($attachment->width)->toBe(120)->and($attachment->thumbnail_path)->not->toBeNull();
});

test('画素数が極端に多い画像(ファイルは小さくても表示するブラウザが固まる)は添付できない', function () {
    loginAs($this->customer);
    postIssueWith([UploadedFile::fake()->createWithContent('flood.png', pngHeaderOnly(20000, 20000))])
        ->assertSessionHasErrors('attachments.0');

    expect(Attachment::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('ファイル名から文字の向きを変える制御文字を取り除く(拡張子の偽装表示対策)', function () {
    loginAs($this->customer);
    postIssueWith([UploadedFile::fake()->createWithContent("report\u{202E}fdp.txt", 'log')])->assertRedirect('/issues/WEB-1');

    expect(Attachment::firstOrFail()->original_name)->toBe('reportfdp.txt');
});

test('添付ファイルはブラウザのキャッシュから表示せず毎回確認させ、変更が無ければ304を返す', function () {
    loginAs($this->customer);
    postIssueWith([UploadedFile::fake()->image('shot.png', 200, 100)]);
    $attachment = Attachment::firstOrFail();

    $response = $this->get("/attachments/{$attachment->id}")->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
    $etag = $response->headers->get('ETag');
    expect($etag)->not->toBeEmpty();

    $this->withHeaders(['If-None-Match' => $etag])->get("/attachments/{$attachment->id}")->assertStatus(304);

    // プロジェクトから外れた人は、キャッシュの確認でも見られない
    loginAs($this->otherCustomer);
    $this->withHeaders(['If-None-Match' => $etag])->get("/attachments/{$attachment->id}")->assertNotFound();
});

test('サムネイルが無い画像は、画素数が多すぎる場合は元画像を代わりに返さない', function () {
    $issue = Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);
    Storage::disk('local')->put('attachments/large.png', pngHeaderOnly(6000, 4000));
    Storage::disk('local')->put('attachments/small.png', pngHeaderOnly(800, 600));
    $make = fn (string $path, int $w, int $h) => Attachment::create([
        'issue_id' => $issue->id, 'user_id' => $this->customer->id, 'original_name' => basename($path),
        'path' => $path, 'thumbnail_path' => null, 'mime_type' => 'image/png', 'size' => 100, 'width' => $w, 'height' => $h,
    ]);
    $large = $make('attachments/large.png', 6000, 4000);
    $small = $make('attachments/small.png', 800, 600);

    loginAs($this->customer);
    $this->get("/attachments/{$large->id}/thumbnail")->assertNotFound();
    $this->get("/attachments/{$small->id}/thumbnail")->assertOk();
});

test('添付ファイルを権限チェック無しで配信する仕組み(署名付きURLの /storage/...)は使えない', function () {
    expect(Route::has('storage.local'))->toBeFalse();
});

// ===== その他 =====

test('すべての画面(存在しないURLも含む)に、クリックジャッキング等の対策ヘッダーが付く', function () {
    foreach (['/login', '/no-such-page'] as $url) {
        $this->get($url)
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
});

test('インストーラのホスト名は、接続文字列に別の設定を紛れ込ませられる形式を受け付けない', function () {
    expect(InstallerService::validateHost('db.example.com'))->toBeNull()
        ->and(InstallerService::validateHost('127.0.0.1'))->toBeNull()
        ->and(InstallerService::validateHost('::1'))->toBeNull()
        ->and(InstallerService::validateHost('127.0.0.1;unix_socket=/tmp/x.sock'))->not->toBeNull()
        ->and(InstallerService::validateHost("db\nexample"))->not->toBeNull();
});

test('インストール・アップデートはWebの画面からはできない(SSHでコマンドを実行する)', function () {
    $this->get('/install')->assertNotFound();
    $this->post('/install')->assertNotFound();

    loginAs(User::factory()->staff()->create());
    $this->get('/staff/update')->assertNotFound();
    $this->post('/staff/update')->assertNotFound();
});
