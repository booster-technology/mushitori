<?php

use App\Services\InstallerService;
use App\Support\Installer\PhpFpmUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
| サーバーの設定の案内のテスト:
| PHP-FPMの実行ユーザーの検出、コマンド版インストーラの「次にやること」、Nginx設定の表示(app:nginx-config)
*/

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mushitori-guidance-'.Str::random(8);
    mkdir($this->tmp, 0777, true);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

function poolConfig(string $name, string $content): string
{
    $path = test()->tmp.DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, $content);

    return $path;
}

test('PHP-FPMの設定ファイルから、実行ユーザーとグループを調べる(コメント行は無視する)', function () {
    $rhel = poolConfig('rhel.conf', "[www]\n; user = nobody\nuser = apache\n;group = nobody\ngroup = apache\nlisten = /run/php-fpm/www.sock\n");
    $debian = poolConfig('debian.conf', "[www]\nuser = www-data\n");

    expect(PhpFpmUser::detect([$rhel]))->toBe(['user' => 'apache', 'group' => 'apache'])
        // group が無ければユーザーと同じ
        ->and(PhpFpmUser::detect([$debian]))->toBe(['user' => 'www-data', 'group' => 'www-data'])
        // 最初に見つかったもの
        ->and(PhpFpmUser::detect([$this->tmp.'/none.conf', $debian, $rhel]))->toBe(['user' => 'www-data', 'group' => 'www-data'])
        ->and(PhpFpmUser::detect([$this->tmp.'/none.conf']))->toBeNull();
});

test('コマンド版インストーラの「次にやること」は、PHP-FPMの実際の実行ユーザーで権限・cron を案内し、setup-server.sh も伝える', function () {
    config(['installer.php_fpm_pool_configs' => [poolConfig('www.conf', "user = apache\ngroup = apache\n")]]);
    $settings = [
        'mode' => 'production', 'url' => 'https://issues.example.com', 'db' => 'mysql', 'mail' => 'log',
        'backup_daily' => true, 'trusted_proxies' => InstallerService::PRIVATE_NETWORKS,
    ];

    $steps = implode("\n", app(InstallerService::class)->nextSteps($settings, '/path/to/nginx.conf'));

    expect($steps)->toContain('sudo bash '.str_replace('\\', '/', base_path()).'/deploy/setup-server.sh')
        ->toContain('sudo chown -R apache:apache storage && sudo chgrp -R apache bootstrap/cache .env && sudo chmod -R g+w bootstrap/cache && sudo chmod 640 .env')
        ->toContain('sudo -u apache php artisan app:backup')
        ->toContain('sudo crontab -u apache -e')
        ->toContain('cd '.str_replace('\\', '/', base_path()).' && php artisan schedule:run')
        // ロードバランサーの後ろでは、ALBのアイドルタイムアウトも案内する
        ->toContain('アイドルタイムアウトを120秒');

    // 分からない場合は仮の文字で案内する
    config(['installer.php_fpm_pool_configs' => [$this->tmp.'/none.conf']]);
    $steps = implode("\n", app(InstallerService::class)->nextSteps($settings, '/path/to/nginx.conf'));
    expect($steps)->toContain('sudo chown -R <Webサーバーの実行ユーザー> storage && sudo chgrp -R <Webサーバーのグループ> bootstrap/cache .env')
        ->toContain('sudo crontab -u <Webサーバーの実行ユーザー> -e');
});

test('app:nginx-config は、URL・インストール先に合わせたNginx設定を表示する(ファイルには書き込まない)', function () {
    $base = str_replace('\\', '/', base_path());

    $this->artisan('app:nginx-config', ['--url' => 'https://issues.example.com', '--behind-proxy' => true])
        ->expectsOutputToContain('server_name issues.example.com;')
        ->expectsOutputToContain("root {$base}/public;")
        ->expectsOutputToContain('client_max_body_size 110M;')
        ->expectsOutputToContain('fastcgi_param REMOTE_ADDR $realip_remote_addr;')
        ->assertSuccessful();

    // ロードバランサーの後ろでなければ、HTTPS(証明書はドメインの Let's Encrypt)・HTTPのみの設定
    $this->artisan('app:nginx-config', ['--url' => 'https://issues.example.com'])
        ->expectsOutputToContain('/etc/letsencrypt/live/issues.example.com/')
        ->assertSuccessful();
    $this->artisan('app:nginx-config', ['--url' => 'http://192.168.1.10'])
        ->expectsOutputToContain('server_name 192.168.1.10;')
        ->doesntExpectOutputToContain('ssl_certificate')
        ->assertSuccessful();
});

test('app:nginx-config は、Nginxの設定に別の設定を紛れ込ませられるドメインを受け付けない', function () {
    $this->artisan('app:nginx-config', ['--url' => 'https://issues.example.com;include'])
        ->expectsOutputToContain('ホスト名は英数字・ドット・ハイフン')
        ->assertFailed();
    $this->artisan('app:nginx-config', ['--url' => 'issues.example.com'])
        ->expectsOutputToContain('http:// または https:// で始まるURL')
        ->assertFailed();
});
