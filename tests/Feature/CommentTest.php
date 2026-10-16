<?php

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Mail\IssueCommentedMail;
use App\Models\Attachment;
use App\Models\Comment;
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
    $this->assignee = User::factory()->staff()->create(['name' => '担当 次郎']);
    $this->customer = User::factory()->customer()->create();
    $this->project = Project::factory()->create(['key' => 'WEB']);
    $this->project->members()->attach([$this->staff->id, $this->customer->id]);

    $this->issue = Issue::factory()->create([
        'project_id' => $this->project->id,
        'reporter_id' => $this->customer->id,
        'assignee_id' => $this->assignee->id,
    ]);
});

test('お客様がコメントすると担当者と社内メンバーに通知され、状態の変更履歴が残る', function () {
    loginAs($this->customer);

    $this->post('/issues/WEB-1/comments', ['body' => '再現手順を追記します', 'status' => 'closed'])
        ->assertRedirect();

    $comment = Comment::first();
    // 変更履歴は toEqual で比べる(MySQLのJSON型はキーの順番を並べ替えて保存するため、順番までは比べない)
    expect($comment->body)->toBe('再現手順を追記します')
        ->and($comment->change_log)->toEqual([['field' => 'status', 'from' => 'open', 'to' => 'closed']]);

    $issue = $this->issue->fresh();
    expect($issue->status)->toBe(IssueStatus::Closed)->and($issue->closed_at)->not->toBeNull();

    Mail::assertSent(IssueCommentedMail::class, fn ($m) => $m->hasTo($this->assignee->email));
    Mail::assertSent(IssueCommentedMail::class, fn ($m) => $m->hasTo($this->staff->email));
    Mail::assertNotSent(IssueCommentedMail::class, fn ($m) => $m->hasTo($this->customer->email));
});

test('社内メンバーのコメントは起票者(お客様)に通知され、リンクはお客様用URLになる', function () {
    loginAs($this->assignee);

    $this->post('/staff/issues/WEB-1/comments', ['body' => '修正しました。ご確認ください。', 'status' => 'resolved']);

    Mail::assertSent(IssueCommentedMail::class, function (IssueCommentedMail $m) {
        $html = $m->render();

        return $m->hasTo($this->customer->email)
            && str_contains($html, '/issues/WEB-1')
            && ! str_contains($html, '/staff/issues/WEB-1')
            && str_contains($html, '状態: 未対応 → 処理済み');
    });
});

test('社内メンバーは担当者・優先度・期限日を変更でき、すべて変更履歴に残る', function () {
    loginAs($this->staff);

    $this->post('/staff/issues/WEB-1/comments', [
        'status' => 'in_progress',
        'assignee_id' => $this->staff->id,
        'priority' => 'high',
        'due_date' => '2030-03-31',
    ])->assertRedirect();

    $issue = $this->issue->fresh();
    expect($issue->status)->toBe(IssueStatus::InProgress)
        ->and($issue->assignee_id)->toBe($this->staff->id)
        ->and($issue->priority)->toBe(IssuePriority::High)
        ->and($issue->due_date->format('Y-m-d'))->toBe('2030-03-31');

    // MySQLのJSON型はキーの順番を並べ替えて保存するため toEqual で比べる(履歴の並び順は比べる)
    expect(Comment::first()->change_log)->toEqual([
        ['field' => 'status', 'from' => 'open', 'to' => 'in_progress'],
        ['field' => 'assignee', 'from' => '担当 次郎', 'to' => '社内 太郎'],
        ['field' => 'priority', 'from' => 'normal', 'to' => 'high'],
        ['field' => 'due_date', 'from' => null, 'to' => '2030-03-31'],
    ]);

    // 変更履歴は詳細画面に表示される
    $this->get('/staff/issues/WEB-1')->assertSee('担当 次郎')->assertSee('2030/03/31');
});

test('お客様が担当者・優先度・期限日を送っても無視される', function () {
    loginAs($this->customer);

    $this->post('/issues/WEB-1/comments', [
        'body' => 'よろしくお願いします',
        'assignee_id' => '',
        'priority' => 'high',
        'due_date' => '2030-01-01',
    ])->assertRedirect();

    $issue = $this->issue->fresh();
    expect($issue->assignee_id)->toBe($this->assignee->id)
        ->and($issue->priority)->toBe(IssuePriority::Normal)
        ->and($issue->due_date)->toBeNull()
        ->and(Comment::first()->change_log)->toBeNull();
});

test('本文も変更も添付も無いコメントは投稿できない', function () {
    loginAs($this->customer);

    $this->post('/issues/WEB-1/comments', ['body' => '   ', 'status' => 'open'])
        ->assertSessionHasErrors('body');
    expect(Comment::count())->toBe(0);
});

test('コメントに画像を添付できる', function () {
    loginAs($this->customer);

    $this->post('/issues/WEB-1/comments', [
        'attachments' => [UploadedFile::fake()->image('after.png', 300, 200)],
    ])->assertRedirect();

    $comment = Comment::first();
    expect($comment->attachments)->toHaveCount(1)
        ->and($this->issue->attachments()->count())->toBe(0); // 課題本文の添付には含まれない
});

test('他プロジェクトのお客様はコメントできない', function () {
    $outsider = User::factory()->customer()->create();
    loginAs($outsider);

    $this->post('/issues/WEB-1/comments', ['body' => 'test'])->assertNotFound();
    expect(Comment::count())->toBe(0);
});

test('自分のコメントは削除でき(論理削除)、本文と添付は表示されなくなるがデータと変更履歴は残る', function () {
    loginAs($this->customer);
    $this->post('/issues/WEB-1/comments', [
        'body' => '間違えて投稿',
        'status' => 'in_progress',
        'attachments' => [UploadedFile::fake()->image('x.png', 20, 20)],
    ]);
    $comment = Comment::first();
    $attachment = $comment->attachments()->first();

    $this->delete("/comments/{$comment->id}")->assertRedirect('/issues/WEB-1');

    // データ(本文・変更履歴)は残る
    $deleted = Comment::withTrashed()->find($comment->id);
    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->body)->toBe('間違えて投稿')
        ->and($deleted->change_log)->not->toBeNull();

    // 添付ファイルも論理削除され、ファイルの実体は残るが配信はしない
    expect(Attachment::withTrashed()->find($attachment->id)->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($attachment->path);
    $this->get("/attachments/{$attachment->id}")->assertNotFound();
    $this->get("/attachments/{$attachment->id}/thumbnail")->assertNotFound();

    // 画面には「削除されました」と変更履歴だけを表示し、本文は出さない
    $this->get('/issues/WEB-1')->assertSee('このコメントは削除されました')->assertDontSee('間違えて投稿');
});

test('お客様は他人のコメントを削除できない(社内メンバーは削除できる)', function () {
    $comment = Comment::factory()->create(['issue_id' => $this->issue->id, 'user_id' => $this->staff->id]);

    loginAs($this->customer);
    $this->delete("/comments/{$comment->id}")->assertForbidden();
    expect($comment->fresh()->trashed())->toBeFalse();

    loginAs($this->assignee);
    $this->delete("/staff/comments/{$comment->id}")->assertRedirect();
    expect(Comment::withTrashed()->find($comment->id)->trashed())->toBeTrue();
});

test('コメントすると課題の更新日時が新しくなる', function () {
    $this->travel(-1)->days();
    $issue = Issue::factory()->create(['project_id' => $this->project->id, 'reporter_id' => $this->customer->id]);
    $this->travelBack();
    $before = $issue->updated_at;

    loginAs($this->customer);
    $this->post('/issues/'.$issue->issueKey().'/comments', ['body' => '追記']);

    expect($issue->fresh()->updated_at->gt($before))->toBeTrue();
});

test('担当者が無効化されていても、社内メンバーがコメントしただけでは担当者は外れない', function () {
    $this->assignee->update(['is_active' => false]);
    loginAs($this->staff);

    // 画面の選択肢には無効化された現担当者も残る
    $this->get('/staff/issues/WEB-1')->assertSee('担当 次郎（無効）');

    $this->post('/staff/issues/WEB-1/comments', [
        'body' => '確認します',
        'status' => 'open',
        'assignee_id' => $this->assignee->id,
        'priority' => 'normal',
        'due_date' => '',
    ])->assertSessionHasNoErrors();

    expect($this->issue->fresh()->assignee_id)->toBe($this->assignee->id)
        ->and(Comment::first()->change_log)->toBeNull();
});
