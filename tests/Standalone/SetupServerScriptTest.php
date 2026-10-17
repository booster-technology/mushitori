<?php

use App\Services\InstallerService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
| サーバー設定スクリプト(deploy/setup-server.sh)のテスト。
| root 権限で動かすスクリプトなので、chown・nginx・crontab・systemctl などは記録するだけの代役に置き換え、
| 一時ディレクトリに作ったムシトリのフォルダに対して、どの場面で何をするか(しないか)を確認する
*/

/**
 * テストに使う bash(Windows では Git for Windows 等の bash。WSL の bash は使わない)
 */
function findBash(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return is_file('/bin/bash') ? '/bin/bash' : null;
    }
    $found = [];
    exec('where bash 2>NUL', $found);
    foreach (array_merge(['C:\\Program Files\\Git\\bin\\bash.exe'], $found) as $path) {
        $path = trim($path);
        if ($path !== '' && is_file($path) && ! str_contains(strtolower($path), 'system32')) {
            return $path;
        }
    }

    return null;
}

beforeEach(function () {
    $this->bash = findBash();
    if ($this->bash === null) {
        $this->markTestSkipped('bash がありません');
    }

    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mushitori-setup-'.Str::random(8);
    $this->appDir = $this->tmp.'/app';
    foreach (['deploy', 'storage/logs', 'bootstrap/cache', 'nginx', 'bin', 'record'] as $dir) {
        File::ensureDirectoryExists(($dir === 'nginx' || $dir === 'bin' || $dir === 'record' ? $this->tmp : $this->appDir).'/'.$dir);
    }
    copy(base_path('deploy/setup-server.sh'), $this->appDir.'/deploy/setup-server.sh');
    File::copyDirectory(base_path('deploy/nginx'), $this->appDir.'/deploy/nginx');
    File::ensureDirectoryExists($this->tmp.'/letsencrypt');
    file_put_contents($this->appDir.'/artisan', '');
    // インストール済み(コマンド版インストーラが書いた .env。ALBの後ろ)
    file_put_contents($this->appDir.'/.env', "APP_URL=https://issues.example.com\nTRUSTED_PROXIES='10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'\n");

    // 代役のコマンド(呼ばれた内容を record/calls に記録する)
    $stubs = [
        // root として動かす。ユーザーは存在する扱い
        'id' => 'case "$1" in -u) echo 0 ;; -gn) echo "$2" ;; -un) echo deployer ;; *) exit 0 ;; esac',
        // 一式を設置したユーザー(artisan の所有者)
        'stat' => 'echo deployer',
        // php は cron の行に使うだけ(Nginx の設定は PHP を使わずにスクリプト自身が作る)
        'php' => 'exit 0',
        'nginx' => 'case "$1" in -T) cat "$RECORD/nginx-dump" 2>/dev/null || true ;; -t) echo "nginx -t" >> "$RECORD/calls"; exit "${STUB_NGINX_T:-0}" ;; esac',
        'crontab' => 'if [ "$3" = "-l" ]; then cat "$RECORD/crontab" 2>/dev/null || exit 1; else cat > "$RECORD/crontab"; fi',
        // 状態の確認(is-active)は記録しない(変更を伴う操作だけ記録する)
        'systemctl' => '[ "$1" = "is-active" ] && exit 0; echo "systemctl $*" >> "$RECORD/calls"',
        'chown' => 'echo "chown $*" >> "$RECORD/calls"',
        'chmod' => 'echo "chmod $*" >> "$RECORD/calls"',
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents($this->tmp."/bin/{$name}", "#!/usr/bin/env bash\n{$body}\n");
        chmod($this->tmp."/bin/{$name}", 0755);
    }
    // 代役を優先して使うよう PATH の先頭に置いて実行する
    file_put_contents($this->tmp.'/run.sh', implode("\n", [
        '#!/usr/bin/env bash',
        'here="$(cd "$(dirname "$0")" && pwd)"',
        'export PATH="$here/bin:$PATH" RECORD="$here/record"',
        'exec bash "$here/app/deploy/setup-server.sh" "$@"',
    ])."\n");
});

afterEach(function () {
    if (isset($this->tmp)) {
        File::deleteDirectory($this->tmp);
    }
});

/**
 * スクリプトを実行する(Nginx の設定ファイルは一時ディレクトリに置かせる)
 */
function setupServer(array $args, array $env = []): ProcessResult
{
    return Process::path(test()->tmp)
        ->env($env + ['LETSENCRYPT_LIVE_DIR' => test()->tmp.'/letsencrypt'])
        ->timeout(60)
        ->run(array_merge([test()->bash, 'run.sh', '--yes', '--web-user=apache', '--nginx-conf='.test()->tmp.'/nginx/mushitori.conf'], $args));
}

function setupCalls(): string
{
    return (string) @file_get_contents(test()->tmp.'/record/calls');
}

test('権限を付け、Nginx の設定を置いて再読み込みし、cron を登録する(URL・ALBの後ろかどうかは .env から)', function () {
    $result = setupServer([]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('ロードバランサーの後ろ')
        ->and($result->output())->toContain('サーバーの設定が完了しました');

    $calls = setupCalls();
    expect($calls)->toMatch('#chown -R -h apache:apache \S+/storage
#')
        // bootstrap/cache は設置したユーザーと Webサーバーの両方が書き込める(update.sh が動くように)
        ->and($calls)->toMatch('#chown -R -h deployer:apache \S+/bootstrap/cache
#')
        ->and($calls)->toMatch('#chmod -R g\+rwX \S+/bootstrap/cache
#')
        // .env は他のユーザーから読めないように
        ->and($calls)->toMatch('#chown -h deployer:apache \S+/\.env
#')
        ->and($calls)->toMatch('#chmod 640 \S+/\.env
#')
        // 一式のフォルダ自体の所有者は変えない
        ->and($calls)->not->toMatch('#chown \S+ \S+/app\n#')
        ->and($calls)->toContain('nginx -t')
        ->and($calls)->toContain('systemctl reload nginx')
        ->and((string) file_get_contents($this->tmp.'/nginx/mushitori.conf'))->toContain('server_name issues.example.com;')
        ->and((string) file_get_contents($this->tmp.'/record/crontab'))->toMatch('#cd \S+/app && \S+ artisan schedule:run >> /dev/null 2>&1#');
});

test('cron が登録済みなら何もしない(php のパスが違っても、同じフォルダの schedule:run なら登録済み)', function () {
    // スクリプトから見えるフォルダのパス(Windows の bash では /c/... になる)で書いておく
    $appPath = trim(Process::path($this->appDir)->run([$this->bash, '-c', 'pwd'])->output());
    $registered = '* * * * * cd '.$appPath." && php artisan schedule:run >> /dev/null 2>&1\n";
    file_put_contents($this->tmp.'/record/crontab', $registered);

    $result = setupServer([]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('登録済みです(変更なし)')
        ->and((string) file_get_contents($this->tmp.'/record/crontab'))->toBe($registered);
});

test('インストール前(.env が無い)は、何もせずに止める', function () {
    unlink($this->appDir.'/.env');

    $result = setupServer([]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('先に bash install.sh でインストールしてから実行してください')
        ->and(setupCalls())->toBe('');
});

test('同じドメインの設定が他のファイルにある場合は、Nginx の設定を変えない(他のサイトを壊さない)', function () {
    file_put_contents($this->tmp.'/record/nginx-dump', implode("\n", [
        '# configuration file /etc/nginx/conf.d/default.conf:',
        'server {',
        '    #server_name issues.example.com;',
        '    server_name example.com www.example.com;',
        '}',
        '# configuration file /etc/nginx/conf.d/manual.conf:',
        'server {',
        '    server_name issues.example.com;',
        '}',
    ])."\n");

    $result = setupServer(['--url=https://issues.example.com']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('同じドメイン(issues.example.com)の設定が既に次のファイルにあるため、変更しません: /etc/nginx/conf.d/manual.conf')
        // コメントアウトした行・別のドメインは同じドメインとみなさない
        ->and($result->output())->not->toContain('default.conf')
        ->and(file_exists($this->tmp.'/nginx/mushitori.conf'))->toBeFalse()
        ->and(setupCalls())->not->toContain('systemctl reload nginx');
});

test('nginx -t が通らなければ、設定を元に戻して再読み込みしない', function () {
    file_put_contents($this->tmp.'/nginx/mushitori.conf', "# 元の設定\n");

    $result = setupServer(['--url=https://issues.example.com'], ['STUB_NGINX_T' => '1']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput().$result->output())->toContain('設定を元に戻しました')
        ->and((string) file_get_contents($this->tmp.'/nginx/mushitori.conf'))->toBe("# 元の設定\n")
        ->and(setupCalls())->not->toContain('systemctl reload nginx')
        // 元の設定のバックアップを残す
        ->and(glob($this->tmp.'/nginx/mushitori.conf.bak.*'))->toHaveCount(1);
});

test('--dry-run では何も変更しない', function () {
    $result = setupServer(['--dry-run', '--url=https://issues.example.com']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('何も変更していません')
        ->and(setupCalls())->toBe('')
        ->and(file_exists($this->tmp.'/nginx/mushitori.conf'))->toBeFalse()
        ->and(file_exists($this->tmp.'/record/crontab'))->toBeFalse();
});

/**
 * スクリプトから見える設置フォルダのパス(Windows の bash では /tmp/... などになる)
 */
function setupAppPath(): string
{
    return trim(Process::path(test()->appDir)->run([test()->bash, '-c', 'pwd'])->output());
}

test('Nginx の設定は、インストーラ(app:nginx-config)と同じ内容をスクリプト自身が作る(Web側のPHPの出力は使わない)', function (string $env, array $args, array $settings) {
    file_put_contents($this->appDir.'/.env', $env);
    if (str_starts_with($settings['url'], 'https://') && empty($settings['trusted_proxies'])) {
        File::ensureDirectoryExists($this->tmp.'/letsencrypt/issues.example.com');
        file_put_contents($this->tmp.'/letsencrypt/issues.example.com/fullchain.pem', 'dummy');
    }

    $result = setupServer($args);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());

    // インストーラが作る設定の設置場所の部分を、スクリプトから見たパスにして比べる
    $expected = str_replace(str_replace(DIRECTORY_SEPARATOR, '/', base_path()), setupAppPath(), app(InstallerService::class)->nginxConfig($settings));
    expect(trim((string) file_get_contents($this->tmp.'/nginx/mushitori.conf')))->toBe(trim($expected));
})->with([
    'ALBの後ろ' => ["APP_URL=https://issues.example.com\nTRUSTED_PROXIES='10.0.0.0/8'\n", [], ['url' => 'https://issues.example.com', 'trusted_proxies' => '10.0.0.0/8']],
    'HTTPS(証明書あり)' => ["APP_URL=https://issues.example.com\n", [], ['url' => 'https://issues.example.com', 'trusted_proxies' => null]],
    'HTTPのみ' => ["APP_URL=http://issues.example.com\n", [], ['url' => 'http://issues.example.com', 'trusted_proxies' => null]],
    '--behind-proxy=no でALBを使わない' => ["APP_URL=http://issues.example.com\nTRUSTED_PROXIES='10.0.0.0/8'\n", ['--behind-proxy=no'], ['url' => 'http://issues.example.com', 'trusted_proxies' => null]],
]);

test('HTTPS の証明書がまだ無い場合は、いったん HTTP の設定を置き、certbot --nginx で HTTPS にするよう案内する', function () {
    file_put_contents($this->appDir.'/.env', "APP_URL=https://issues.example.com\n");

    $result = setupServer([]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('HTTPS の証明書がまだ無いため')
        ->and($result->output())->toContain('sudo certbot --nginx -d issues.example.com');
    $conf = (string) file_get_contents($this->tmp.'/nginx/mushitori.conf');
    expect($conf)->toContain('server_name issues.example.com;')
        ->and($conf)->not->toContain('ssl_certificate');
});

test('SQLite のデータベースは、設置フォルダの中にある場合だけ権限を変える(.env を書き換えて他の場所の所有者を変えさせない)', function () {
    $appPath = setupAppPath();
    File::ensureDirectoryExists($this->appDir.'/database');
    file_put_contents($this->appDir.'/database/database.sqlite', '');

    file_put_contents($this->appDir.'/.env', "APP_URL=http://issues.example.com\nDB_CONNECTION=sqlite\nDB_DATABASE=/etc/passwd\n");
    $result = setupServer(['--no-nginx', '--no-cron']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('設置フォルダの外にあるため、権限は変えません')
        ->and(setupCalls())->not->toContain('/etc');

    @unlink($this->tmp.'/record/calls');
    file_put_contents($this->appDir.'/.env', "APP_URL=http://issues.example.com\nDB_CONNECTION=sqlite\nDB_DATABASE={$appPath}/database/database.sqlite\n");
    $result = setupServer(['--no-nginx', '--no-cron']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and(setupCalls())->toContain("chown -h apache:apache {$appPath}/database\n")
        ->and(setupCalls())->toContain("chown -h apache:apache {$appPath}/database/database.sqlite\n");
});

test('公開URLのドメインに、Nginx の設定を紛れ込ませられる文字があれば止める', function () {
    file_put_contents($this->appDir.'/.env', "APP_URL=http://issues.example.com;include\n");

    $result = setupServer([]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('公開URLのドメインが正しくありません')
        ->and(file_exists($this->tmp.'/nginx/mushitori.conf'))->toBeFalse();
});

test('--help は使い方だけを表示する(スクリプトの中身は出さない)', function () {
    $result = Process::path($this->tmp)->run([$this->bash, 'run.sh', '--help']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('オプション:')
        ->and($result->output())->toContain('--dry-run')
        ->and($result->output())->not->toContain('set -euo pipefail')
        ->and($result->output())->not->toContain('APP_DIR=');
});

test('同梱のシェルスクリプトに文法の誤りが無い', function (string $script) {
    $result = Process::path(base_path())->run([$this->bash, '-n', $script]);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
})->with(['install.sh', 'update.sh', 'deploy/setup-server.sh']);
