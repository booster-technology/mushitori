<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| バックアップ(php artisan app:backup)のテスト。
| DB(SQLiteファイル)・保存先・.env はすべて一時ディレクトリに作る
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mushitori-backup-'.Str::random(8);
    mkdir($this->tmp.DIRECTORY_SEPARATOR.'env', 0777, true);
    file_put_contents($this->tmp.'/env/.env', "APP_NAME=backup-test\nDB_PASSWORD=secret\n");
    $this->app->useEnvironmentPath($this->tmp.DIRECTORY_SEPARATOR.'env');
    $this->backupDir = $this->tmp.DIRECTORY_SEPARATOR.'backups';

    $database = $this->tmp.DIRECTORY_SEPARATOR.'app.sqlite';
    touch($database);
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    File::deleteDirectory($this->tmp);
});

/**
 * バックアップ(zip)の中身を一時ディレクトリに展開してパスを返す
 */
function extractBackup(string $zipPath, string $to): string
{
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->extractTo($to);
    $zip->close();

    return $to;
}

test('データベース・添付ファイル・.env・復元手順が1つのファイルにまとめられ、そこからデータを戻せる', function () {
    // 課題と添付ファイルを登録しておく
    $customer = User::factory()->customer()->create();
    $project = Project::factory()->create(['key' => 'WEB']);
    $project->members()->attach($customer->id);
    loginAs($customer);
    $this->post('/projects/WEB/issues', [
        'type' => 'bug', 'title' => 'バックアップ対象の課題', 'priority' => 'normal',
        'attachments' => [UploadedFile::fake()->image('shot.png', 100, 80)],
    ]);
    $attachment = Issue::first()->attachments()->first();

    $this->artisan('app:backup', ['--path' => $this->backupDir])->assertSuccessful();

    $files = glob($this->backupDir.'/backup-*.zip');
    expect($files)->toHaveCount(1)
        // ファイル名は日時+ランダムな文字列(推測されないように)
        ->and(basename($files[0]))->toMatch('/^backup-\d{8}-\d{6}-[a-z0-9]{8}\.zip$/');

    $out = extractBackup($files[0], $this->tmp.'/extracted');
    expect(file_exists("$out/database.sqlite"))->toBeTrue()
        ->and(file_exists("$out/{$attachment->path}"))->toBeTrue()
        ->and(file_exists("$out/{$attachment->thumbnail_path}"))->toBeTrue()
        ->and(file_get_contents("$out/.env"))->toContain('APP_NAME=backup-test')
        ->and(file_get_contents("$out/RESTORE.txt"))->toContain('復元手順');

    // バックアップのDBに課題が入っている(=このファイルから戻せる)
    $pdo = new PDO('sqlite:'."$out/database.sqlite");
    expect($pdo->query('select title from issues')->fetchColumn())->toBe('バックアップ対象の課題')
        ->and((int) $pdo->query('select count(*) from attachments')->fetchColumn())->toBe(1);
    $pdo = null;
});

test('--without-env で .env(パスワード等)を含めないバックアップも作れる', function () {
    $this->artisan('app:backup', ['--path' => $this->backupDir, '--without-env' => true])->assertSuccessful();

    $out = extractBackup(glob($this->backupDir.'/backup-*.zip')[0], $this->tmp.'/extracted');
    expect(file_exists("$out/.env"))->toBeFalse()
        ->and(file_get_contents("$out/RESTORE.txt"))->toContain('.env は含まれていません');
});

test('指定した世代数を超えた古いバックアップは削除される', function () {
    File::ensureDirectoryExists($this->backupDir);
    foreach (['20260101-030000', '20260102-030000', '20260103-030000'] as $stamp) {
        touch($this->backupDir."/backup-{$stamp}.zip");
    }
    touch($this->backupDir.'/my-important-file.zip'); // バックアップ以外のファイルは消さない

    $this->artisan('app:backup', ['--path' => $this->backupDir, '--keep' => 2])->assertSuccessful();

    $remaining = array_map('basename', glob($this->backupDir.'/*.zip'));
    sort($remaining);
    expect($remaining)->toHaveCount(3)
        ->and($remaining)->toContain('backup-20260103-030000.zip')
        ->and($remaining)->toContain('my-important-file.zip')
        ->and($remaining)->not->toContain('backup-20260101-030000.zip')
        ->and($remaining)->not->toContain('backup-20260102-030000.zip');
});

test('mysqldump が見つからない場合は、分かりやすいエラーで失敗し、作業ファイルを残さない', function () {
    // MySQL接続として扱わせる(実際には接続しない)
    config([
        'database.connections.mysql_backup_test' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'x', 'username' => 'u', 'password' => 'p'],
        'database.default' => 'mysql_backup_test',
        'backup.mysqldump' => 'mysqldump-does-not-exist',
    ]);

    $this->artisan('app:backup', ['--path' => $this->backupDir])
        ->expectsOutputToContain('mysqldump が見つかりません')
        ->assertFailed();

    expect(glob($this->backupDir.'/*') ?: [])->toBe([])
        ->and(glob($this->backupDir.'/.work-*') ?: [])->toBe([]);
});

test('MySQL の mysqldump では GTID の情報を出力しない(RDS 等で FLUSH TABLES の権限が無くてもダンプできるように)', function (string $help, bool $expectsGtidOption) {
    config([
        'database.connections.mysql_backup_test' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'x', 'username' => 'u', 'password' => 'p'],
        'database.default' => 'mysql_backup_test',
        'backup.mysqldump' => PHP_BINARY, // 実際には実行しない(Process::fake)
    ]);
    Process::fake(function (PendingProcess $process) use ($help) {
        $command = (array) $process->command;
        if (in_array('--help', $command, true)) {
            return Process::result($help);
        }
        // ダンプの代わりに、指定された出力先にSQLを書き出す
        foreach ($command as $arg) {
            if (str_starts_with($arg, '--result-file=')) {
                file_put_contents(substr($arg, strlen('--result-file=')), '-- dump');
            }
        }

        return Process::result();
    });

    $this->artisan('app:backup', ['--path' => $this->backupDir])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => in_array('--single-transaction', (array) $process->command, true)
        && in_array('--set-gtid-purged=OFF', (array) $process->command, true) === $expectsGtidOption);
    $out = extractBackup(glob($this->backupDir.'/backup-*.zip')[0], $this->tmp.'/extracted');
    expect(file_get_contents("$out/database.sql"))->toBe('-- dump');
})->with([
    // MySQL の mysqldump は --set-gtid-purged に対応している
    'MySQL' => ['  --set-gtid-purged[=name]  Add \'SET @@GLOBAL.GTID_PURGED\' to the output.', true],
    // MariaDB の mysqldump には無い(付けると不明なオプションのエラーになる)
    'MariaDB' => ['  --gtid  Used together with --master-data=1 or --dump-slave=1.', false],
]);

test('毎日の自動バックアップは BACKUP_DAILY=true の時だけ深夜3時にスケジュールされる', function () {
    $backupEvent = fn () => collect(Schedule::events())
        ->first(fn ($event) => str_contains((string) $event->command, 'app:backup'));

    // 既定(false)ではスケジュールされない
    config(['backup.daily' => false]);
    require base_path('routes/console.php');
    expect($backupEvent())->toBeNull();

    // true にすると毎日3:00に実行される
    config(['backup.daily' => true]);
    require base_path('routes/console.php');
    expect($backupEvent())->not->toBeNull()
        ->and($backupEvent()->expression)->toBe('0 3 * * *');
});

test('MySQLの接続設定に改行が含まれていたら、mysqldump の設定ファイルを作らずに中止する', function () {
    // 改行で別の設定(読み込むプラグインの場所など)を紛れ込ませようとした場合
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.password' => "secret\nplugin-dir=/tmp/evil",
        'backup.mysqldump' => PHP_BINARY, // 実行はされない(設定ファイルを作る前に中止する)
    ]);
    DB::purge('mysql');

    expect(fn () => app(BackupService::class)->create($this->backupDir))
        ->toThrow(RuntimeException::class, '制御文字');
    expect(glob($this->backupDir.'/*') ?: [])->toBe([]);
});
