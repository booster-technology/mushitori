<?php

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| 削除は論理削除(データ・ファイルは残し、画面・配信からは見えなくする)のテスト
*/

test('プロジェクト・課題・コメント・添付ファイルの削除は論理削除で、データと添付ファイルの実体は残る', function () {
    Mail::fake();
    Storage::fake('local');
    $staff = User::factory()->staff()->create();
    $project = Project::factory()->create(['key' => 'WEB']);
    $project->members()->attach($staff->id);

    loginAs($staff);
    $this->post('/staff/projects/WEB/issues', [
        'type' => 'bug', 'title' => '削除のテスト', 'priority' => 'normal',
        'attachments' => [UploadedFile::fake()->image('issue.png', 40, 30)],
    ])->assertRedirect('/staff/issues/WEB-1');
    $this->post('/staff/issues/WEB-1/comments', [
        'body' => 'コメント', 'attachments' => [UploadedFile::fake()->image('comment.png', 40, 30)],
    ]);
    $issue = Issue::firstOrFail();
    $comment = Comment::firstOrFail();
    $files = Attachment::all()->flatMap(fn (Attachment $a) => array_filter([$a->path, $a->thumbnail_path]))->all();

    // コメント → 課題 → プロジェクトの順に削除する
    $this->delete("/staff/comments/{$comment->id}")->assertRedirect();
    $this->delete('/staff/issues/WEB-1')->assertRedirect();
    $this->delete('/staff/projects/WEB')->assertRedirect();

    // 画面からは見えない
    $this->get('/staff/issues/WEB-1')->assertNotFound();
    expect(Project::count())->toBe(0)->and(Issue::count())->toBe(0)->and(Comment::count())->toBe(0);

    // データは削除日時が記録されて残っている
    expect(Project::withTrashed()->find($project->id)->trashed())->toBeTrue()
        ->and(Issue::withTrashed()->find($issue->id)->trashed())->toBeTrue()
        ->and(Comment::withTrashed()->find($comment->id)->body)->toBe('コメント')
        ->and(Attachment::withTrashed()->count())->toBe(2);

    // 添付ファイル・サムネイルの実体も残っている
    expect($files)->toHaveCount(4);
    Storage::disk('local')->assertExists($files);
});

test('利用者のデータを持つモデルは論理削除に対応し、物理削除(forceDelete)はゴミ箱(TrashService)以外でしていない', function () {
    foreach ([Project::class, Issue::class, Comment::class, Attachment::class] as $model) {
        expect(class_uses_recursive($model))->toContain(SoftDeletes::class);
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        // ゴミ箱の完全削除(保管期間を過ぎたもの・社内メンバーが完全に削除したもの)だけは物理削除する
        if (str_ends_with($file->getFilename(), '.php') && $file->getFilename() !== 'TrashService.php') {
            $code = (string) file_get_contents($file->getPathname());
            expect($code)->not->toContain('forceDelete')
                ->not->toContain('->truncate(');
        }
    }
});
