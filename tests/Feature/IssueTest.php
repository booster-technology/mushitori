<?php

use App\Enums\IssueStatus;
use App\Mail\IssueCommentedMail;
use App\Mail\IssueCreatedMail;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    $this->staff = User::factory()->staff()->create(['name' => '社内 太郎']);
    $this->customer = User::factory()->customer()->create();
    $this->project = Project::factory()->create(['key' => 'WEB']);
    $this->project->members()->attach([$this->staff->id, $this->customer->id]);

    // 別プロジェクトとそのお客様(他社)
    $this->otherCustomer = User::factory()->customer()->create();
    $this->otherProject = Project::factory()->create(['key' => 'OTH']);
    $this->otherProject->members()->attach($this->otherCustomer->id);
});

function issuePayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'bug',
        'title' => 'ログイン後に画面が真っ白になる',
        'description' => "【操作手順】\n1. ログインする",
        'priority' => 'high',
    ], $overrides);
}

test('お客様は所属プロジェクトに課題を登録でき、課題キーが連番で振られる', function () {
    loginAs($this->customer);

    $this->post('/projects/WEB/issues', issuePayload())->assertRedirect('/issues/WEB-1');
    $this->post('/projects/WEB/issues', issuePayload(['title' => '2件目']))->assertRedirect('/issues/WEB-2');

    $issue = Issue::where('number', 1)->first();
    expect($issue->project_id)->toBe($this->project->id)
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($issue->reporter_id)->toBe($this->customer->id)
        ->and($issue->issueKey())->toBe('WEB-1');
});

test('お客様が登録すると、プロジェクトの社内メンバーに通知メールが届く(本人には届かない)', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload());

    Mail::assertSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($this->staff->email));
    Mail::assertNotSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($this->customer->email));
});

test('通知メールのリンクは受信者のポータル(社内用URL)になっている', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload());

    Mail::assertSent(IssueCreatedMail::class, function (IssueCreatedMail $mail) {
        return str_contains($mail->render(), '/staff/issues/WEB-1');
    });
});

test('プロジェクトに社内メンバーがいない場合は、通知漏れを防ぐため社内全員に通知する', function () {
    $this->project->members()->detach($this->staff->id);
    $anotherStaff = User::factory()->staff()->create();
    User::factory()->staff()->inactive()->create(['email' => 'inactive@example.com']);

    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload());

    Mail::assertSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($this->staff->email));
    Mail::assertSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($anotherStaff->email));
    Mail::assertNotSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo('inactive@example.com'));
});

test('お客様は担当者・期限日を指定できない(送っても無視される)', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload([
        'assignee_id' => $this->staff->id,
        'due_date' => '2030-01-01',
    ]));

    $issue = Issue::first();
    expect($issue->assignee_id)->toBeNull()->and($issue->due_date)->toBeNull();
});

test('社内メンバーは担当者・期限日を指定して登録でき、担当者に通知される', function () {
    $assignee = User::factory()->staff()->create();
    loginAs($this->staff);

    $this->post('/staff/projects/WEB/issues', issuePayload([
        'assignee_id' => $assignee->id,
        'due_date' => '2030-01-31',
    ]))->assertRedirect('/staff/issues/WEB-1');

    $issue = Issue::first();
    expect($issue->assignee_id)->toBe($assignee->id)
        ->and($issue->due_date->format('Y-m-d'))->toBe('2030-01-31');
    Mail::assertSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($assignee->email));
});

test('担当者にお客様や無効な社内メンバーは指定できない', function () {
    loginAs($this->staff);
    $inactiveStaff = User::factory()->staff()->inactive()->create();

    $this->post('/staff/projects/WEB/issues', issuePayload(['assignee_id' => $this->customer->id]))
        ->assertSessionHasErrors('assignee_id');
    $this->post('/staff/projects/WEB/issues', issuePayload(['assignee_id' => $inactiveStaff->id]))
        ->assertSessionHasErrors('assignee_id');
    expect(Issue::count())->toBe(0);
});

test('社内メンバーはお客様の代わりに課題を登録でき(代理起票)、お客様に登録のお知らせが届く', function () {
    loginAs($this->staff);
    $this->post('/staff/projects/WEB/issues', issuePayload(['reporter_id' => $this->customer->id]))
        ->assertRedirect('/staff/issues/WEB-1');

    // 起票者はお客様、代わりに登録した人は社内メンバー
    $issue = Issue::first();
    expect($issue->reporter_id)->toBe($this->customer->id)
        ->and($issue->registered_by)->toBe($this->staff->id);

    // お客様には「お問い合わせの内容を登録した」旨のメール(お客様用のURL)が届き、登録した本人には届かない
    Mail::assertSent(IssueCreatedMail::class, function (IssueCreatedMail $mail) {
        $html = $mail->render();

        return $mail->hasTo($this->customer->email)
            && str_contains($html, 'お問い合わせいただいた内容を')
            && str_contains($html, url('/issues/WEB-1'))
            && ! str_contains($html, '/staff/');
    });
    Mail::assertNotSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($this->staff->email));

    // お客様の画面では自分の課題として表示され、代わりに登録した人も分かる。自分が起票した課題として編集できる
    loginAs($this->customer);
    $this->get('/issues/WEB-1')->assertOk()->assertSee('社内 太郎 が代わりに登録');
    $this->put('/issues/WEB-1', ['title' => 'お客様が補足した件名', 'type' => 'bug'])->assertRedirect('/issues/WEB-1');
    expect($issue->fresh()->title)->toBe('お客様が補足した件名');
});

test('代理起票した課題のコメントは、起票者のお客様に通知される', function () {
    loginAs($this->staff);
    $this->post('/staff/projects/WEB/issues', issuePayload(['reporter_id' => $this->customer->id]));
    $colleague = User::factory()->staff()->create();
    Mail::fake();

    loginAs($colleague);
    $this->post('/staff/issues/WEB-1/comments', ['body' => '確認しました', 'status' => 'in_progress']);

    Mail::assertSent(IssueCommentedMail::class, fn ($mail) => $mail->hasTo($this->customer->email));
});

test('社内メンバーが自分を起票者として登録した場合は、お客様に通知されず代理の記録も残らない', function () {
    loginAs($this->staff);
    $this->post('/staff/projects/WEB/issues', issuePayload(['reporter_id' => '']));

    $issue = Issue::first();
    expect($issue->reporter_id)->toBe($this->staff->id)
        ->and($issue->registered_by)->toBeNull();
    Mail::assertNotSent(IssueCreatedMail::class, fn ($mail) => $mail->hasTo($this->customer->email));
    $this->get('/staff/issues/WEB-1')->assertDontSee('が代わりに登録');
});

test('代理起票の起票者には、そのプロジェクトの有効なお客様しか選べない', function () {
    loginAs($this->staff);
    $inactiveCustomer = User::factory()->customer()->inactive()->create();
    $this->project->members()->attach($inactiveCustomer->id);
    $anotherStaff = User::factory()->staff()->create();
    $this->project->members()->attach($anotherStaff->id);

    foreach ([$this->otherCustomer->id, $inactiveCustomer->id, $anotherStaff->id, 999999] as $reporterId) {
        $this->post('/staff/projects/WEB/issues', issuePayload(['reporter_id' => $reporterId]))
            ->assertSessionHasErrors('reporter_id');
    }
    expect(Issue::count())->toBe(0);
});

test('お客様は起票者を指定できない(他人を指定しても自分が起票者になる)', function () {
    $colleague = User::factory()->customer()->create();
    $this->project->members()->attach($colleague->id);
    loginAs($this->customer);

    $this->post('/projects/WEB/issues', issuePayload(['reporter_id' => $colleague->id]))->assertRedirect('/issues/WEB-1');

    $issue = Issue::first();
    expect($issue->reporter_id)->toBe($this->customer->id)
        ->and($issue->registered_by)->toBeNull();
});

test('課題の追加画面の起票者の選択肢は、社内用の画面にだけ、そのプロジェクトのお客様が出る', function () {
    loginAs($this->staff);
    $this->get('/staff/projects/WEB/issues/create')->assertOk()
        ->assertSee('name="reporter_id"', false)
        ->assertSee($this->customer->displayName())
        ->assertDontSee($this->otherCustomer->displayName());

    loginAs($this->customer);
    $this->get('/projects/WEB/issues/create')->assertOk()->assertDontSee('name="reporter_id"', false);
});

test('必須項目が無いと登録できない', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', ['type' => 'unknown'])
        ->assertSessionHasErrors(['title', 'type', 'priority']);
    expect(Issue::count())->toBe(0);
});

test('お客様は所属していないプロジェクトの課題を見られない・登録できない(404)', function () {
    $otherIssue = Issue::factory()->create(['project_id' => $this->otherProject->id, 'reporter_id' => $this->otherCustomer->id]);
    loginAs($this->customer);

    $this->get('/projects/OTH/issues')->assertNotFound();
    $this->get('/projects/OTH/issues/create')->assertNotFound();
    $this->post('/projects/OTH/issues', issuePayload())->assertNotFound();
    $this->get('/issues/'.$otherIssue->issueKey())->assertNotFound();
    expect(Issue::count())->toBe(1);
});

test('社内メンバーは全プロジェクトの課題を見られる', function () {
    $otherIssue = Issue::factory()->create(['project_id' => $this->otherProject->id, 'reporter_id' => $this->otherCustomer->id]);
    loginAs($this->staff);

    $this->get('/staff/projects/OTH/issues')->assertOk()->assertSee($otherIssue->title);
    $this->get('/staff/issues/'.$otherIssue->issueKey())->assertOk()->assertSee($otherIssue->title);
});

test('スクリーンショットを添付するとファイルとサムネイルが保存され、権限のある人だけ表示できる', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload([
        'attachments' => [
            UploadedFile::fake()->image('screenshot.png', 1600, 900),
            UploadedFile::fake()->create('log.txt', 3, 'text/plain'),
        ],
    ]))->assertRedirect('/issues/WEB-1');

    $issue = Issue::first();
    $image = $issue->attachments()->where('original_name', 'screenshot.png')->first();
    $text = $issue->attachments()->where('original_name', 'log.txt')->first();

    expect($image->mime_type)->toBe('image/png')
        ->and($image->width)->toBe(1600)
        ->and($image->thumbnail_path)->not->toBeNull();
    Storage::disk('local')->assertExists([$image->path, $image->thumbnail_path, $text->path]);

    // サムネイルは縮小されたJPEG
    [$tw, $th] = getimagesize(Storage::disk('local')->path($image->thumbnail_path));
    expect($tw)->toBeLessThanOrEqual(480)->and($th)->toBeLessThanOrEqual(360);

    // 画像はインライン表示、テキストはダウンロード
    $this->get("/attachments/{$image->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get("/attachments/{$image->id}/thumbnail")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($this->get("/attachments/{$text->id}")->headers->get('Content-Disposition'))->toContain('attachment');

    // 別プロジェクトのお客様は見られない
    loginAs($this->otherCustomer);
    $this->get("/attachments/{$image->id}")->assertNotFound();
    $this->get("/attachments/{$image->id}/thumbnail")->assertNotFound();
});

test('SVGや拡張子を偽装したファイルは添付できない', function (UploadedFile $file) {
    loginAs($this->customer);

    $this->post('/projects/WEB/issues', issuePayload(['attachments' => [$file]]))
        ->assertSessionHasErrors('attachments.0');
    expect(Issue::count())->toBe(0);
})->with([
    'SVG' => fn () => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'PHPを画像に偽装' => fn () => UploadedFile::fake()->createWithContent('evil.png', '<?php echo "hacked"; ?>'),
    'HTML' => fn () => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
    'サイズ超過' => fn () => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
]);

test('添付ファイルは11個以上同時に送れない', function () {
    loginAs($this->customer);
    $files = collect(range(1, 11))->map(fn ($i) => UploadedFile::fake()->image("s{$i}.png", 10, 10))->all();

    $this->post('/projects/WEB/issues', issuePayload(['attachments' => $files]))
        ->assertSessionHasErrors('attachments');
});

test('課題一覧はデフォルトで完了以外を表示し、キーワード・課題キーで絞り込める', function () {
    $open = Issue::factory()->create(['project_id' => $this->project->id, 'title' => '請求書PDFが文字化けする', 'reporter_id' => $this->customer->id]);
    $closed = Issue::factory()->create(['project_id' => $this->project->id, 'title' => '完了済みの課題', 'status' => IssueStatus::Closed, 'reporter_id' => $this->customer->id]);
    $other = Issue::factory()->create(['project_id' => $this->project->id, 'title' => 'メニューの表示崩れ', 'reporter_id' => $this->customer->id]);
    loginAs($this->customer);

    $this->get('/projects/WEB/issues')->assertOk()
        ->assertSee($open->title)->assertSee($other->title)->assertDontSee($closed->title);
    $this->get('/projects/WEB/issues?status=all')->assertSee($closed->title);
    $this->get('/projects/WEB/issues?status=closed')->assertSee($closed->title)->assertDontSee($open->title);
    $this->get('/projects/WEB/issues?q=文字化け')->assertSee($open->title)->assertDontSee($other->title);
    $this->get('/projects/WEB/issues?q=web-'.$other->number)->assertSee($other->title)->assertDontSee($open->title);
    // LIKEの特殊文字を含むキーワードでもエラーにならない
    $this->get('/projects/WEB/issues?q=%25_')->assertOk();
});

test('課題一覧は20件ごとにページングされる', function () {
    Issue::factory()->count(25)->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);
    loginAs($this->customer);

    $this->get('/projects/WEB/issues')->assertOk()->assertSee('全25件')->assertSee('page=2');
    $this->get('/projects/WEB/issues?page=2')->assertOk()->assertSee('21〜25件目');
});

test('起票者本人は課題を編集でき、同じプロジェクトの別のお客様は編集できない', function () {
    $issue = Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);
    $colleague = User::factory()->customer()->create();
    $this->project->members()->attach($colleague->id);

    loginAs($this->customer);
    $this->put('/issues/WEB-1', ['title' => '修正後の件名', 'type' => 'request', 'description' => '修正'])
        ->assertRedirect('/issues/WEB-1');
    expect($issue->fresh()->title)->toBe('修正後の件名');

    loginAs($colleague);
    $this->get('/issues/WEB-1')->assertOk();
    $this->get('/issues/WEB-1/edit')->assertForbidden();
    $this->put('/issues/WEB-1', ['title' => '勝手に変更', 'type' => 'bug'])->assertForbidden();
    expect($issue->fresh()->title)->toBe('修正後の件名');
});

test('編集時に添付ファイルを追加・削除できる', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload(['attachments' => [UploadedFile::fake()->image('old.png', 20, 20)]]));
    $issue = Issue::first();
    $old = $issue->attachments()->first();

    $this->put('/issues/WEB-1', [
        'title' => $issue->title,
        'type' => 'bug',
        'remove_attachments' => [$old->id],
        'attachments' => [UploadedFile::fake()->image('new.png', 20, 20)],
    ])->assertRedirect('/issues/WEB-1');

    expect($issue->attachments()->pluck('original_name')->all())->toBe(['new.png']);
    // 削除は論理削除: データとファイルの実体は残るが、画面・配信からは見えなくなる
    expect(Attachment::find($old->id))->toBeNull()
        ->and(Attachment::withTrashed()->find($old->id)->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($old->path);
    $this->get("/attachments/{$old->id}")->assertNotFound();
});

test('課題の削除は社内メンバーのみ', function () {
    $issue = Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);

    loginAs($this->customer);
    $this->delete('/issues/WEB-1')->assertForbidden();
    expect($issue->fresh()->trashed())->toBeFalse();

    loginAs($this->staff);
    $this->delete('/staff/issues/WEB-1')->assertRedirect('/staff/projects/WEB/issues');
    expect($issue->fresh()->trashed())->toBeTrue();
    $this->get('/staff/issues/WEB-1')->assertNotFound();
});

test('詳細画面の本文はHTMLエスケープされ、URLはリンクになる', function () {
    Issue::factory()->create([
        'project_id' => $this->project->id,
        'reporter_id' => $this->customer->id,
        'description' => "<script>alert(1)</script>\nhttps://example.com/page?a=1",
    ]);
    loginAs($this->customer);

    $this->get('/issues/WEB-1')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('<a href="https://example.com/page?a=1" target="_blank" rel="noopener noreferrer">', false);
});

test('ホーム画面には所属プロジェクトだけが表示される', function () {
    loginAs($this->customer);
    $this->get('/')->assertOk()->assertSee($this->project->name)->assertDontSee($this->otherProject->name);

    loginAs($this->staff);
    $this->get('/staff')->assertOk()->assertSee($this->project->name)->assertSee($this->otherProject->name);
});

test('URLの自動リンクは直後の日本語を含めない', function () {
    expect(format_text('詳細はhttps://example.com/a?b=1&c=2です。'))
        ->toBe('詳細は<a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">https://example.com/a?b=1&amp;c=2</a>です。');
});

test('所属していないプロジェクトの課題は編集画面も404になる', function () {
    $otherIssue = Issue::factory()->create(['project_id' => $this->otherProject->id, 'reporter_id' => $this->otherCustomer->id]);
    loginAs($this->customer);

    $this->get('/issues/'.$otherIssue->issueKey().'/edit')->assertNotFound();
    $this->put('/issues/'.$otherIssue->issueKey(), ['title' => 'x', 'type' => 'bug'])->assertNotFound();
});

test('所属していないプロジェクトには、入力が不正でもバリデーションより先に404を返す(存在を知らせない)', function () {
    $otherIssue = Issue::factory()->create(['project_id' => $this->otherProject->id, 'reporter_id' => $this->otherCustomer->id]);
    loginAs($this->customer);

    $this->post('/projects/OTH/issues', [])->assertNotFound();
    $this->post('/issues/'.$otherIssue->issueKey().'/comments', ['status' => 'invalid'])->assertNotFound();
    $this->put('/issues/'.$otherIssue->issueKey(), [])->assertNotFound();
});

test('キーワード検索で「%」「_」を文字として扱う', function () {
    Issue::factory()->create(['project_id' => $this->project->id, 'title' => '割引率100%の表示', 'reporter_id' => $this->customer->id]);
    Issue::factory()->create(['project_id' => $this->project->id, 'title' => '割引率1000の表示', 'reporter_id' => $this->customer->id]);
    Issue::factory()->create(['project_id' => $this->project->id, 'title' => 'user_id が空', 'reporter_id' => $this->customer->id]);
    Issue::factory()->create(['project_id' => $this->project->id, 'title' => 'userAid が空', 'reporter_id' => $this->customer->id]);
    loginAs($this->customer);

    $this->get('/projects/WEB/issues?q='.urlencode('100%'))->assertSee('割引率100%の表示')->assertDontSee('割引率1000の表示');
    $this->get('/projects/WEB/issues?q='.urlencode('user_id'))->assertSee('user_id が空')->assertDontSee('userAid が空');
});

test('通知メールに件名・本文のMarkdownリンクが埋め込まれない', function () {
    loginAs($this->customer);
    $this->post('/projects/WEB/issues', issuePayload([
        'title' => '[ログインはこちら](https://evil.example)',
        'description' => '詳細は [こちら](https://evil.example/phish) を参照',
    ]));

    Mail::assertSent(IssueCreatedMail::class, function (IssueCreatedMail $mail) {
        $html = $mail->render();

        return ! str_contains($html, 'href="https://evil.example')
            && str_contains($html, 'ログインはこちら');
    });
});
