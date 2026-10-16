<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\InstallerService;
use App\Support\Installer\EnvFile;
use Dotenv\Dotenv;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| インストーラ(php artisan app:install)のテスト。
| .env・SQLiteファイル・生成物はすべて一時ディレクトリに作り、本物の .env や DB には触れない
*/

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mushitori-install-'.Str::random(8);
    mkdir($this->tmp.DIRECTORY_SEPARATOR.'storage', 0777, true);
    $this->app->useEnvironmentPath($this->tmp);
    $this->app->useStoragePath($this->tmp.DIRECTORY_SEPARATOR.'storage');
    $this->sqlite = $this->tmp.DIRECTORY_SEPARATOR.'test.sqlite';
    Mail::fake();
});

afterEach(function () {
    DB::purge();
    File::deleteDirectory($this->tmp);
});

/**
 * 一時ディレクトリに作られた .env を Laravel と同じパーサーで読む
 */
function installedEnv(string $dir): array
{
    return Dotenv::parse((string) file_get_contents($dir.DIRECTORY_SEPARATOR.'.env'));
}

test('オプション指定で本番用に自動インストールでき、.env・DB・管理者・Nginx設定が作られる', function () {
    $password = "p'a\$ss #1 \"x\"";

    $this->artisan('app:install', [
        '--mode' => 'production',
        '--name' => 'テスト課題管理',
        '--url' => 'https://issues.example.test/',
        '--theme-color' => 'b8332e',
        '--db' => 'sqlite',
        '--sqlite-path' => $this->sqlite,
        '--mail' => 'smtp',
        '--mail-host' => 'smtp.example.test',
        '--mail-port' => '587',
        '--mail-encryption' => 'tls',
        '--mail-username' => 'mailer',
        '--mail-password' => $password,
        '--mail-from' => 'noreply@example.test',
        '--staff-path' => 'staff-abc123',
        '--admin-name' => '管理 太郎',
        '--admin-email' => 'Admin@Example.test',
        '--backup' => 'yes',
        '--behind-proxy' => 'no',
        '--no-interaction' => true,
    ])->expectsConfirmation('この内容でインストールしますか？', 'yes')->assertSuccessful();

    $env = installedEnv($this->tmp);
    expect($env['APP_NAME'])->toBe('テスト課題管理')
        ->and($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['APP_URL'])->toBe('https://issues.example.test')
        ->and($env['APP_KEY'])->toStartWith('base64:')
        ->and($env['STAFF_PATH'])->toBe('staff-abc123')
        ->and($env['APP_THEME_COLOR'])->toBe('#B8332E')
        ->and($env['BACKUP_DAILY'])->toBe('true')
        ->and($env['TRUSTED_PROXIES'])->toBe('')
        ->and($env['LOG_LEVEL'])->toBe('info')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['DB_CONNECTION'])->toBe('sqlite')
        ->and($env['MAIL_MAILER'])->toBe('smtp')
        ->and($env['MAIL_PASSWORD'])->toBe($password); // 記号入りのパスワードもそのまま保存される

    // テーブルが作られ、管理者が社内メンバーとして登録されている
    $admin = User::where('email', 'admin@example.test')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(UserRole::Staff)
        ->and($admin->is_active)->toBeTrue();

    // URL・インストール先に合わせたNginx設定
    $nginx = (string) file_get_contents($this->tmp.'/storage/app/install/nginx.conf');
    expect($nginx)->toContain('server_name issues.example.test;')
        ->toContain('ssl_certificate')
        ->toContain('root '.str_replace('\\', '/', base_path()).'/public;');
});

test('SQLite・メール送信なしのお試しインストールを対話形式で行える', function () {
    $this->artisan('app:install', ['--sqlite-path' => $this->sqlite])
        ->expectsQuestion('どこで使いますか？', 'local')
        ->expectsQuestion('サイト名（画面の左上やメールに表示されます）', 'お試し課題管理')
        ->expectsQuestion('テーマカラー（画面のヘッダーやボタンの色）', 'green')
        ->expectsQuestion('公開URL（ブラウザで開くときのURL）', 'http://127.0.0.1:8000')
        ->expectsQuestion('データベースの種類', 'sqlite')
        ->expectsQuestion('メールの送り方', 'log')
        ->expectsQuestion('社内メンバー用ログイン画面のURL（/○○/login の ○○ の部分）', 'staff')
        ->expectsQuestion('最初の社内メンバー（管理者）の氏名', '試用 花子')
        ->expectsQuestion('最初の社内メンバー（管理者）のメールアドレス', 'trial@example.test')
        ->expectsConfirmation('この内容でインストールしますか？', 'yes')
        ->assertSuccessful();

    $env = installedEnv($this->tmp);
    expect($env['APP_ENV'])->toBe('local')
        ->and($env['APP_DEBUG'])->toBe('true')
        ->and($env['MAIL_MAILER'])->toBe('log')
        ->and($env['APP_THEME_COLOR'])->toBe('#1F8A70')
        ->and($env['BACKUP_DAILY'])->toBe('false') // お試し用では自動バックアップしない
        ->and(User::where('email', 'trial@example.test')->exists())->toBeTrue();

    // お試し用ではNginx設定は作らない
    expect(file_exists($this->tmp.'/storage/app/install/nginx.conf'))->toBeFalse();
});

test('インストール済みの場合は何もせず終了し、暗号鍵(APP_KEY)も作り直さない', function () {
    $options = [
        '--mode' => 'local', '--name' => 'A', '--url' => 'http://localhost:8000', '--theme-color' => 'navy', '--db' => 'sqlite',
        '--sqlite-path' => $this->sqlite, '--mail' => 'log', '--staff-path' => 'staff',
        '--admin-name' => '管理', '--admin-email' => 'a@example.test', '--no-interaction' => true,
    ];
    $this->artisan('app:install', $options)->expectsConfirmation('この内容でインストールしますか？', 'yes')->assertSuccessful();
    $key = installedEnv($this->tmp)['APP_KEY'];

    $this->artisan('app:install', ['--name' => 'B'] + $options)
        ->expectsOutputToContain('既にインストールされています')
        ->assertSuccessful();
    expect(installedEnv($this->tmp)['APP_NAME'])->toBe('A');

    // --force で再実行しても APP_KEY は維持される
    $this->artisan('app:install', ['--name' => 'B', '--force' => true] + $options)
        ->expectsConfirmation('この内容でインストールしますか？', 'yes')
        ->assertSuccessful();
    expect(installedEnv($this->tmp)['APP_NAME'])->toBe('B')
        ->and(installedEnv($this->tmp)['APP_KEY'])->toBe($key);
});

test('不正なオプション値はエラーで止まる', function (array $bad) {
    $this->artisan('app:install', $bad + [
        '--mode' => 'local', '--name' => 'A', '--url' => 'http://localhost:8000', '--theme-color' => 'navy', '--db' => 'sqlite',
        '--sqlite-path' => $this->sqlite, '--mail' => 'log', '--staff-path' => 'staff',
        '--admin-name' => '管理', '--admin-email' => 'a@example.test', '--no-interaction' => true,
    ])->assertFailed();

    expect(file_exists($this->sqlite))->toBeFalse();
})->with([
    'URLの形式' => [['--url' => 'issues.example.com']],
    'メールアドレスの形式' => [['--admin-email' => 'not-an-email']],
    'お客様用画面と重なる社内用URL' => [['--staff-path' => 'login']],
    '存在しないDB種別' => [['--db' => 'oracle']],
    'テーマカラーの形式' => [['--theme-color' => 'red; background:url(x)']],
]);

test('.env の値は記号を含んでも正しく読み書きできる', function (string $value) {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'.env.test';
    file_put_contents($path, "# コメント\nAPP_NAME=old\n# DB_PASSWORD=\n");

    (new EnvFile($path))->set('APP_NAME', $value)->set('DB_PASSWORD', $value)->save();

    $parsed = Dotenv::parse((string) file_get_contents($path));
    expect($parsed['APP_NAME'])->toBe($value)
        ->and($parsed['DB_PASSWORD'])->toBe($value)
        ->and((new EnvFile($path))->get('DB_PASSWORD'))->toBe($value)
        ->and(file_get_contents($path))->toStartWith('# コメント');
})->with(['simple', 'has space', 'a#b', 'pa$$word', "it's", "it's \$HOME \"q\" \\back", '${APP_NAME}', '日本語の名前']);

test('app:create-staff で社内メンバーを追加・復旧できる', function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge();
    $this->artisan('migrate', ['--force' => true]);
    $customer = User::factory()->customer()->inactive()->create(['email' => 'x@example.test']);

    $this->artisan('app:create-staff', ['email' => 'new@example.test', '--name' => '新人'])->assertSuccessful();
    // お客様を社内メンバーに変える場合は確認がある
    $this->artisan('app:create-staff', ['email' => 'X@example.test'])
        ->expectsConfirmation('x@example.test はお客様として登録されています。社内メンバーに変更しますか？（全プロジェクトの課題を見られるようになります）', 'yes')
        ->assertSuccessful();

    expect(User::where('email', 'new@example.test')->first()->isStaff())->toBeTrue()
        ->and($customer->fresh()->isStaff())->toBeTrue()
        ->and($customer->fresh()->is_active)->toBeTrue();
});

test('コマンド版インストーラが中断した理由はログにも残る', function () {
    Log::spy();

    $this->artisan('app:install', [
        '--mode' => 'local', '--name' => 'A', '--theme-color' => 'navy', '--url' => 'issues.example.com', '--no-interaction' => true,
    ])->assertFailed();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'インストールを中断しました')
        && str_contains($message, '--url'))->once();
});

test('ロードバランサー(ALB)の後ろで動かす場合は、TRUSTED_PROXIES を設定し、ALB用のNginx設定を作る', function () {
    $this->artisan('app:install', [
        '--mode' => 'production', '--name' => 'A', '--url' => 'https://issues.example.test', '--theme-color' => 'navy',
        '--behind-proxy' => 'yes', '--db' => 'sqlite', '--sqlite-path' => $this->sqlite, '--mail' => 'log',
        '--staff-path' => 'staff-abc123', '--admin-name' => '管理', '--admin-email' => 'a@example.test',
        '--backup' => 'no', '--no-interaction' => true,
    ])->expectsConfirmation('この内容でインストールしますか？', 'yes')->assertSuccessful();

    $env = installedEnv($this->tmp);
    expect($env['TRUSTED_PROXIES'])->toBe('10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')
        // メールを送信しない設定では、認証コード(メールの内容)が debug レベルでログに出るので、本番でも debug にする
        ->and($env['LOG_LEVEL'])->toBe('debug');

    $nginx = (string) file_get_contents($this->tmp.'/storage/app/install/nginx.conf');
    expect($nginx)->toContain('server_name issues.example.test;')
        ->toContain('real_ip_header X-Forwarded-For;')
        ->not->toContain('ssl_certificate'); // HTTPSはALBで終端するので、このサーバーでは証明書を使わない
});

test('メールを Amazon SES(IAMロール)にすると、アクセスキーを書かずに ses-v2 で送る設定になる', function () {
    $this->artisan('app:install', [
        '--mode' => 'production', '--name' => 'A', '--url' => 'https://issues.example.test', '--theme-color' => 'navy',
        '--behind-proxy' => 'no', '--db' => 'sqlite', '--sqlite-path' => $this->sqlite,
        '--mail' => 'ses', '--ses-region' => 'ap-northeast-1', '--mail-from' => 'noreply@example.test',
        '--staff-path' => 'staff-abc123', '--admin-name' => '管理', '--admin-email' => 'a@example.test',
        '--backup' => 'no', '--no-interaction' => true,
    ])->expectsConfirmation('この内容でインストールしますか？', 'yes')->assertSuccessful();

    $env = installedEnv($this->tmp);
    expect($env['MAIL_MAILER'])->toBe('ses-v2')
        ->and($env['AWS_DEFAULT_REGION'])->toBe('ap-northeast-1')
        ->and($env['MAIL_FROM_ADDRESS'])->toBe('noreply@example.test')
        // サーバーのIAMロールで送るので、アクセスキーは書かない
        ->and($env['AWS_ACCESS_KEY_ID'] ?? '')->toBe('')
        ->and($env['AWS_SECRET_ACCESS_KEY'] ?? '')->toBe('');
});

test('SESのリージョンの形式が正しくなければインストールしない', function () {
    $this->artisan('app:install', [
        '--mode' => 'production', '--name' => 'A', '--url' => 'https://issues.example.test', '--theme-color' => 'navy',
        '--behind-proxy' => 'no', '--db' => 'sqlite', '--sqlite-path' => $this->sqlite,
        '--mail' => 'ses', '--ses-region' => 'tokyo', '--mail-from' => 'noreply@example.test', '--no-interaction' => true,
    ])->assertFailed();

    expect(InstallerService::validateRegion('ap-northeast-1'))->toBeNull()
        ->and(InstallerService::validateRegion('us-gov-west-1'))->toBeNull()
        ->and(InstallerService::validateRegion('tokyo'))->not->toBeNull();
});

test('ses-v2 の送り方は、アクセスキーが無くても作れる(認証情報は送信時にIAMロールから取得する)', function () {
    config(['services.ses' => ['key' => null, 'secret' => null, 'region' => 'ap-northeast-1']]);

    // (このテストファイルではメールを偽物に差し替えているので、本物のメール設定から作る)
    $transport = (new MailManager(app()))->mailer('ses-v2')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(SesV2Transport::class)
        ->and($transport->ses()->getRegion())->toBe('ap-northeast-1');
});
