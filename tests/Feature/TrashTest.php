<?php

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\CommentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| ゴミ箱(削除したデータの確認・元に戻す・完全に削除・保管期間を過ぎたら自動で完全に削除)のテスト
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    config(['trash.retention_days' => 30]);

    $this->staff = User::factory()->staff()->create(['name' => '社内 太郎']);
    $this->customer = User::factory()->customer()->create(['name' => '顧客 花子']);
    $this->project = Project::factory()->create(['key' => 'WEB', 'name' => 'Webサイト保守']);
    $this->project->members()->attach([$this->staff->id, $this->customer->id]);
});

/**
 * 画像付きの課題(WEB-1)と、状態の変更と画像付きのコメントを作る
 */
function issueWithComment(): array
{
    loginAs(test()->customer);
    test()->post('/projects/WEB/issues', [
        'type' => 'bug', 'title' => '画面が崩れる', 'priority' => 'normal',
        'attachments' => [UploadedFile::fake()->image('issue.png', 40, 30)],
    ])->assertRedirect('/issues/WEB-1');
    test()->post('/issues/WEB-1/comments', [
        'body' => '個人情報が写った画像を貼ってしまいました', 'status' => 'in_progress',
        'attachments' => [UploadedFile::fake()->image('secret.png', 40, 30)],
    ]);

    return [Issue::firstOrFail(), Comment::firstOrFail()];
}

test('削除すると削除した人が記録され、ゴミ箱に種類ごとに表示される(社内メンバーのみ)', function () {
    [$issue, $comment] = issueWithComment();

    loginAs($this->staff);
    $this->delete("/staff/comments/{$comment->id}")->assertRedirect();

    expect(Comment::withTrashed()->find($comment->id)->deleted_by)->toBe($this->staff->id);

    $this->get('/staff')->assertSee('ゴミ箱');
    $this->get('/staff/trash?type=comments')->assertOk()
        ->assertSee('個人情報が写った画像を貼ってしまいました')
        ->assertSee('WEB-1 画面が崩れる')
        ->assertSee('社内 太郎')
        ->assertSee($comment->fresh()?->deleted_at?->addDays(30)?->format('Y/m/d') ?? now()->addDays(30)->format('Y/m/d'))
        ->assertSee('完全に削除');

    // お客様は開けない(社内用のログインを外して、お客様だけがログインしている状態にする)
    auth('staff')->logout();
    loginAs($this->customer);
    $this->get('/staff/trash')->assertRedirect('/staff/login');
});

test('元に戻すと、コメントと一緒に削除した添付ファイルも戻る', function () {
    [$issue, $comment] = issueWithComment();
    $attachment = $comment->attachments()->firstOrFail();

    loginAs($this->staff);
    $this->delete("/staff/comments/{$comment->id}");
    $this->get("/staff/attachments/{$attachment->id}")->assertNotFound();

    $this->from('/staff/trash?type=comments')->post("/staff/trash/comments/{$comment->id}/restore")
        ->assertRedirect('/staff/trash?type=comments')
        ->assertSessionHas('success', '「WEB-1 のコメント」を元に戻しました。');

    $restored = Comment::findOrFail($comment->id);
    expect($restored->deleted_by)->toBeNull()
        ->and($restored->attachments()->count())->toBe(1);
    $this->get("/staff/attachments/{$attachment->id}")->assertOk();
    $this->get('/staff/issues/WEB-1')->assertSee('個人情報が写った画像を貼ってしまいました');
});

test('親の課題・プロジェクトが削除されたままのものは元に戻せず、先に親を戻すよう案内する', function () {
    [$issue, $comment] = issueWithComment();

    loginAs($this->staff);
    $this->delete("/staff/comments/{$comment->id}");
    $this->delete('/staff/issues/WEB-1');
    $this->delete('/staff/projects/WEB');

    $this->from('/staff/trash')->post("/staff/trash/comments/{$comment->id}/restore")
        ->assertSessionHas('error', '課題「WEB-1」が削除されているため、元に戻せません。先に課題を元に戻してください。');
    $this->from('/staff/trash')->post("/staff/trash/issues/{$issue->id}/restore")
        ->assertSessionHas('error', 'プロジェクト「Webサイト保守」が削除されているため、元に戻せません。先にプロジェクトを元に戻してください。');
    expect(Comment::find($comment->id))->toBeNull();

    // プロジェクト → 課題 → コメントの順に戻せる
    $this->post("/staff/trash/projects/{$this->project->id}/restore")->assertSessionHas('success');
    $this->post("/staff/trash/issues/{$issue->id}/restore")->assertSessionHas('success');
    $this->post("/staff/trash/comments/{$comment->id}/restore")->assertSessionHas('success');
    $this->get('/staff/issues/WEB-1')->assertOk()->assertSee('個人情報が写った画像を貼ってしまいました');
});

test('コメントを完全に削除すると、本文と画像の実体は消え、状態などの変更履歴だけが残る', function () {
    Log::spy();
    [$issue, $comment] = issueWithComment();
    $attachment = $comment->attachments()->firstOrFail();

    loginAs($this->staff);
    $this->delete("/staff/comments/{$comment->id}");
    $this->delete("/staff/trash/comments/{$comment->id}")->assertSessionHas('success', '「WEB-1 のコメント」を完全に削除しました。');

    $tombstone = Comment::withTrashed()->findOrFail($comment->id);
    expect($tombstone->body)->toBeNull()
        ->and($tombstone->purged_at)->not->toBeNull()
        ->and($tombstone->change_log)->not->toBeEmpty()
        ->and(Attachment::withTrashed()->find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing(array_filter([$attachment->path, $attachment->thumbnail_path]));

    // ゴミ箱からは消え、課題の画面には「削除されました」と変更履歴が残る
    $this->get('/staff/trash?type=comments')->assertDontSee('個人情報が写った画像を貼ってしまいました');
    $this->get('/staff/issues/WEB-1')->assertSee('このコメントは削除されました')->assertSee('処理中');

    // 誰が完全に削除したかをログに残す
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'ゴミ箱から完全に削除しました'
        && $context['type'] === 'comments' && $context['user_id'] === $this->staff->id)->once();
});

test('課題本文から削除した添付ファイルを完全に削除すると、ファイルの実体も消える', function () {
    [$issue] = issueWithComment();
    $attachment = $issue->attachments()->firstOrFail();

    loginAs($this->staff);
    $this->put('/staff/issues/WEB-1', ['type' => 'bug', 'title' => $issue->title, 'remove_attachments' => [$attachment->id]]);
    $this->get('/staff/trash?type=attachments')->assertSee('issue.png');

    $this->delete("/staff/trash/attachments/{$attachment->id}")->assertSessionHas('success', '「issue.png」を完全に削除しました。');

    expect(Attachment::withTrashed()->find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing(array_filter([$attachment->path, $attachment->thumbnail_path]));
});

test('プロジェクトを完全に削除すると、課題・コメント・添付ファイル(実体も)・メンバーの設定もすべて消える', function () {
    issueWithComment();
    $paths = Attachment::all()->flatMap(fn (Attachment $a) => array_filter([$a->path, $a->thumbnail_path]))->all();

    loginAs($this->staff);
    $this->delete('/staff/projects/WEB');
    $this->delete("/staff/trash/projects/{$this->project->id}")->assertSessionHas('success');

    expect(Project::withTrashed()->count())->toBe(0)
        ->and(Issue::withTrashed()->count())->toBe(0)
        ->and(Comment::withTrashed()->count())->toBe(0)
        ->and(Attachment::withTrashed()->count())->toBe(0)
        ->and(DB::table('project_user')->count())->toBe(0);
    Storage::disk('local')->assertMissing($paths);
});

test('保管期間(30日)を過ぎたものは、毎日の自動処理で完全に削除される', function () {
    [$issue, $comment] = issueWithComment();
    $old = Issue::factory()->create(['project_id' => $this->project->id, 'title' => '古い課題']);
    $recent = Issue::factory()->create(['project_id' => $this->project->id, 'title' => '最近の課題']);

    $this->travelTo(now()->subDays(31));
    $old->trashBy($this->staff);
    app(CommentService::class)->delete($comment, $this->staff);
    $this->travelBack();
    $this->travelTo(now()->subDays(29));
    $recent->trashBy($this->staff);
    $this->travelBack();

    $this->artisan('app:purge-trash')->assertSuccessful();

    expect(Issue::withTrashed()->find($old->id))->toBeNull()
        ->and(Issue::withTrashed()->find($recent->id))->not->toBeNull()
        ->and(Comment::withTrashed()->find($comment->id)->purged_at)->not->toBeNull();

    // 毎日自動で実行する
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'app:purge-trash'));
    expect($events)->toHaveCount(1);
});

test('保管期間を0にすると、自動では削除しない', function () {
    config(['trash.retention_days' => 0]);
    $old = Issue::factory()->create(['project_id' => $this->project->id]);
    $this->travelTo(now()->subYears(2));
    $old->trashBy($this->staff);
    $this->travelBack();

    $this->artisan('app:purge-trash')->expectsOutputToContain('自動では削除しません')->assertSuccessful();

    expect(Issue::withTrashed()->find($old->id))->not->toBeNull();
    loginAs($this->staff);
    $this->get('/staff/trash')->assertSee('自動では削除されません');
});
