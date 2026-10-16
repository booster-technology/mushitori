<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| CSP(Content-Security-Policy)と外部参照のテスト。
| どの画面も外部のサイトから何も読み込まず、インラインのスクリプト・スタイルも使わない
| (= CSPを script-src 'self' / style-src 'self' のまま緩めずに動く)ことを確認する
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    // 本番と同じく、開発用のエラー詳細画面を出さない設定(この設定のときにCSPを付ける)
    config(['app.debug' => false]);
});

test('全画面に、外部から何も読み込まない厳しいCSPを付ける(unsafe-inline・unsafe-eval なし)', function () {
    $policy = $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');

    expect($policy)->toBe(SecurityHeaders::CONTENT_SECURITY_POLICY)
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self';")
        ->toContain("style-src 'self';")
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval')
        ->not->toContain('http');

    // 存在しないURLのエラー画面にも付く
    expect($this->get('/no-such-page')->assertNotFound()->headers->get('Content-Security-Policy'))
        ->toBe(SecurityHeaders::CONTENT_SECURITY_POLICY);
});

test('開発時のエラー詳細画面(APP_DEBUG=true)のためにCSPを付けない', function () {
    config(['app.debug' => true]);

    expect($this->get('/login')->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('すべての画面が、インラインのスクリプト・スタイルや外部参照なしで作られている', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $project = Project::factory()->create(['key' => 'WEB']);
    $project->members()->attach([$staff->id, $customer->id]);

    // 画像の添付とコメントのある課題
    loginAs($customer);
    $this->post('/projects/WEB/issues', [
        'type' => 'bug', 'title' => '表示が崩れる', 'priority' => 'normal',
        'attachments' => [UploadedFile::fake()->image('shot.png', 300, 200)],
    ])->assertRedirect('/issues/WEB-1');
    Comment::factory()->create(['issue_id' => Issue::first()->id, 'user_id' => $staff->id]);

    $pages = [
        '/', '/projects/WEB/issues', '/projects/WEB/issues/create', '/issues/WEB-1', '/issues/WEB-1/edit', '/help',
    ];
    foreach ($pages as $page) {
        expectCspCompatible($this->get($page)->assertOk()->getContent(), $page);
    }

    loginAs($staff);
    $staffPages = [
        '/staff', '/staff/projects', '/staff/projects/create', '/staff/projects/WEB/edit', '/staff/projects/WEB/issues',
        '/staff/issues/WEB-1', '/staff/users', '/staff/users/create', "/staff/users/{$customer->id}/edit",
        '/staff/help', '/staff/help/customer', '/staff/trash', '/staff/trash?type=comments',
    ];
    foreach ($staffPages as $page) {
        expectCspCompatible($this->get($page)->assertOk()->getContent(), $page);
    }

    // エラー画面・ログイン画面
    expectCspCompatible($this->get('/no-such-page')->getContent(), '404');
    expectCspCompatible($this->get('/logout')->getContent(), '405');
    auth('customer')->logout();
    auth('staff')->logout();
    expectCspCompatible($this->get('/login')->getContent(), '/login');
    expectCspCompatible($this->get('/staff/login')->getContent(), '/staff/login');
});

test('同梱しているJavaScriptは eval を使わない(CSPの unsafe-eval が不要)', function () {
    foreach (['public/js/app.js', 'public/vendor/alpinejs/alpine-csp.min.js', 'public/vendor/bootstrap/js/bootstrap.bundle.min.js'] as $path) {
        $code = (string) file_get_contents(base_path($path));
        expect($code)->not->toContain('new Function(')->not->toMatch('/\beval\(/');
    }
});

test('画面のテンプレート(Blade)に、インラインのスクリプト・スタイルを書いていない', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        // メールはメールソフトで表示するもの(CSPの対象外)
        if (! str_ends_with($path, '.blade.php') || str_contains($path, '/views/mail/') || str_contains($path, '/vendor/mail/')) {
            continue;
        }
        // Bladeのコメント({{-- --}})は出力されないので除いてから調べる
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
        expect(preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $source))->toBe(0, "{$path}: インラインのスクリプト")
            ->and(preg_match('/<style[\s>]/i', $source))->toBe(0, "{$path}: <style>")
            ->and(preg_match('/\sstyle\s*=/i', $source))->toBe(0, "{$path}: style属性")
            ->and(preg_match('/javascript:/i', $source))->toBe(0, "{$path}: javascript:")
            ->and(preg_match('/@(push|stack)\(\s*[\'"]scripts/', $source))->toBe(0, "{$path}: scripts スタック")
            ->and(preg_match('/\sx-html\s*=/', $source))->toBe(0, "{$path}: x-html(CSP対応版では使えない)");
    }
});

test('同梱のフォント(Noto Sans JP)のCSSは、同梱したフォントファイルだけを参照する(外部参照なし)', function () {
    $css = (string) file_get_contents(public_path('vendor/noto-sans-jp/noto-sans-jp.css'));
    preg_match_all('/url\(([^)]+)\)/', $css, $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $url) {
        $url = trim($url, '\'"');
        expect($url)->toStartWith('./files/')
            ->and(is_file(public_path('vendor/noto-sans-jp/'.substr($url, 2))))->toBeTrue("{$url} がありません");
    }

    // 全画面で読み込み、文字のフォントとして使う
    expect($this->get('/login')->getContent())->toContain('/vendor/noto-sans-jp/noto-sans-jp.css');
    expect((string) file_get_contents(public_path('css/app.css')))->toContain('--bs-font-sans-serif: "Noto Sans JP Variable"');
});
