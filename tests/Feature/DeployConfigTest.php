<?php

use App\Services\AttachmentService;

/*
| Webサーバー(Nginx・Apache)・PHPの設定例(deploy/)のテスト。
| 画像などの添付ファイルの投稿に必要な値(投稿の上限・処理時間)が、設定例どうし・アプリの上限と揃っていることを確認する
*/

/**
 * php.ini 形式のサイズ(110M など)をバイト数にする
 */
function iniBytes(string $value): int
{
    $number = (int) $value;

    return match (strtoupper(substr(trim($value), -1))) {
        'G' => $number * 1024 ** 3,
        'M' => $number * 1024 ** 2,
        'K' => $number * 1024,
        default => $number,
    };
}

test('PHPの設定例は、アプリの添付ファイルの上限(1ファイル・1回の投稿)を受け付けられる', function () {
    $ini = parse_ini_file(base_path('deploy/php/99-mushitori.ini'));
    $maxFileBytes = AttachmentService::MAX_SIZE_KB * 1024;

    expect(iniBytes($ini['upload_max_filesize']))->toBeGreaterThanOrEqual($maxFileBytes)
        ->and((int) $ini['max_file_uploads'])->toBeGreaterThanOrEqual(AttachmentService::MAX_FILES)
        // 最大数のファイル + 本文などが1回の投稿に収まる
        ->and(iniBytes($ini['post_max_size']))->toBeGreaterThan($maxFileBytes * AttachmentService::MAX_FILES)
        ->and((int) $ini['max_execution_time'])->toBe(120)
        ->and((int) $ini['max_input_time'])->toBe(120);
});

test('レンタルサーバー用の public/.user.ini は、PHPの設定例と同じ値になっている', function () {
    $ini = parse_ini_file(base_path('deploy/php/99-mushitori.ini'));
    $userIni = parse_ini_file(public_path('.user.ini'));

    foreach (['upload_max_filesize', 'post_max_size', 'max_file_uploads', 'max_execution_time', 'max_input_time', 'memory_limit'] as $key) {
        expect($userIni[$key] ?? null)->toBe($ini[$key], $key);
    }
});

test('Nginxの設定例は、投稿の上限・処理時間がPHPと揃い、上限を超えたら日本語の案内を表示する', function (string $file) {
    $ini = parse_ini_file(base_path('deploy/php/99-mushitori.ini'));
    $conf = (string) file_get_contents(base_path("deploy/nginx/{$file}"));

    expect($conf)->toContain('client_max_body_size '.$ini['post_max_size'].';')
        ->toContain('fastcgi_read_timeout '.$ini['max_execution_time'].'s;')
        ->toContain('error_page 413 /errors/413.html;')
        ->toContain("location ^~ /errors/ {\n        internal;")
        // index.php 以外のPHP・隠しファイル(.env 等)は開けない
        ->toContain("location ~ \\.php$ {\n        return 404;")
        ->toContain('location ~ /\.(?!well-known).* {');
})->with(['mushitori.conf', 'mushitori-http.conf', 'mushitori-alb.conf']);

test('Apacheの設定例は、投稿の上限・処理時間がPHPと揃い、上限を超えたら日本語の案内を表示する', function (string $file) {
    $ini = parse_ini_file(base_path('deploy/php/99-mushitori.ini'));
    $conf = (string) file_get_contents(base_path("deploy/apache/{$file}"));

    expect($conf)->toContain('LimitRequestBody '.iniBytes($ini['post_max_size']))
        ->toContain('ProxyTimeout '.$ini['max_execution_time'])
        ->toContain('Timeout '.$ini['max_execution_time'])
        ->toContain('ErrorDocument 413 /errors/413.html')
        ->toContain('DocumentRoot /var/www/mushitori/public')
        ->toContain('AllowOverride None')
        ->toContain('FallbackResource /index.php')
        // index.php 以外のPHP・隠しファイル(.env 等)は開けない
        ->toContain('<FilesMatch "^(?!index\.php$).+\.(php|phar|phtml)$">')
        ->toContain('<FilesMatch "^\.">')
        // mod_remoteip で接続元を書き換えない(利用者のIPはアプリが TRUSTED_PROXIES をもとに判定する)
        ->not->toContain('RemoteIPHeader');
})->with(['mushitori.conf', 'mushitori-http.conf', 'mushitori-alb.conf']);

test('投稿が大きすぎるときにWebサーバーが表示する案内ページは日本語で、CSPのもとでも表示できる', function () {
    $html = (string) file_get_contents(public_path('errors/413.html'));

    expect($html)->toContain('ファイルが大きすぎます')
        ->toContain('1ファイル'.intdiv(AttachmentService::MAX_SIZE_KB, 1024).'MB・1回に'.AttachmentService::MAX_FILES.'ファイルまで');
    expectCspCompatible($html, '/errors/413.html');
});
