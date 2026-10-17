<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use ZipArchive;

/**
 * バックアップの作成と古いバックアップの削除。
 * 1つのファイル(zip、zip拡張が無ければ tar.gz)に、以下をまとめる:
 * - データベース(MySQLは mysqldump の SQL、SQLiteはDBファイルのコピー)
 * - 添付ファイル(storage/app/private/attachments)
 * - .env(復元に必要な APP_KEY・接続設定。パスワード等を含むため取り扱い注意)
 * - 復元手順(RESTORE.txt)
 */
class BackupService
{
    /** バックアップファイル名の接頭辞(古いものを削除する時の目印) */
    private const PREFIX = 'backup-';

    /**
     * バックアップを作成し、作成したファイルのパスを返す
     */
    public function create(string $directory, bool $includeEnv = true): string
    {
        File::ensureDirectoryExists($directory, 0700);

        // 日時の後ろにランダムな文字列を付ける(Webサーバーの設定ミスでファイルが公開されてしまっても、名前を推測されないように)
        $name = self::PREFIX.now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
        $work = $directory.DIRECTORY_SEPARATOR.'.work-'.$name;
        File::ensureDirectoryExists($work, 0700);

        try {
            // 1. データベース
            $databaseFile = $this->dumpDatabase($work);

            // 2. まとめるファイルの一覧(アーカイブ内のパス => 実ファイル)
            $entries = [basename($databaseFile) => $databaseFile];

            $disk = Storage::disk('local');
            foreach ($disk->allFiles('attachments') as $file) {
                $entries[$file] = $disk->path($file);
            }

            $envPath = app()->environmentFilePath();
            if ($includeEnv && is_file($envPath)) {
                $entries['.env'] = $envPath;
            }

            $restoreFile = $work.DIRECTORY_SEPARATOR.'RESTORE.txt';
            file_put_contents($restoreFile, $this->restoreInstructions(basename($databaseFile), $includeEnv));
            $entries['RESTORE.txt'] = $restoreFile;

            // 3. 1つのファイルにまとめる
            $archive = $this->archive($directory.DIRECTORY_SEPARATOR.$name, $entries);
            @chmod($archive, 0600);

            return $archive;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * 新しいものから $keep 個を残して、古いバックアップを削除する。削除したファイルのパスを返す
     *
     * @return list<string>
     */
    public function prune(string $directory, int $keep): array
    {
        if ($keep <= 0 || ! is_dir($directory)) {
            return [];
        }

        // ファイル名に日時が入っているので、名前の降順=新しい順
        $files = array_merge(
            glob($directory.DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [],
            glob($directory.DIRECTORY_SEPARATOR.self::PREFIX.'*.tar.gz') ?: [],
        );
        rsort($files);

        $deleted = [];
        foreach (array_slice($files, $keep) as $file) {
            if (@unlink($file)) {
                $deleted[] = $file;
            }
        }

        return $deleted;
    }

    /**
     * データベースをファイルに書き出し、そのパスを返す
     */
    private function dumpDatabase(string $work): string
    {
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'sqlite' => $this->dumpSqlite($work),
            'mysql', 'mariadb' => $this->dumpMysql($work, $connection->getConfig()),
            default => throw new RuntimeException('このデータベース('.$connection->getDriverName().')のバックアップには対応していません。'),
        };
    }

    /**
     * SQLiteは「VACUUM INTO」で、書き込み中でも整合性の取れたコピーを作る
     */
    private function dumpSqlite(string $work): string
    {
        $target = $work.DIRECTORY_SEPARATOR.'database.sqlite';
        DB::connection()->statement('VACUUM INTO ?', [$target]);

        return $target;
    }

    /**
     * MySQLは mysqldump で、テーブルをロックせずに(--single-transaction)ダンプする。
     * パスワードはコマンドラインに出さず、権限を絞った一時設定ファイルで渡す
     *
     * @param  array<string, mixed>  $config
     */
    private function dumpMysql(string $work, array $config): string
    {
        $binary = (string) config('backup.mysqldump');
        $resolved = is_file($binary) ? $binary : (new ExecutableFinder)->find($binary);
        if ($resolved === null) {
            throw new RuntimeException('mysqldump が見つかりません。MySQLのクライアントをインストールするか、.env の MYSQLDUMP_PATH に mysqldump の場所を指定してください。');
        }

        $target = $work.DIRECTORY_SEPARATOR.'database.sql';
        $optionFile = $work.DIRECTORY_SEPARATOR.'mysql-client.cnf';
        $quote = function ($value): string {
            // 改行を含む値は、設定ファイルに別の設定(読み込むプラグインなど)を紛れ込ませられるため拒否する
            if (preg_match('/[\r\n\0]/', (string) $value)) {
                throw new RuntimeException('データベースの接続設定に改行などの制御文字が含まれているため、バックアップを中止しました。.env を確認してください。');
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        };

        $options = "[client]\n"
            .'user='.$quote($config['username'] ?? '')."\n"
            .'password='.$quote($config['password'] ?? '')."\n";
        if (! empty($config['unix_socket'])) {
            $options .= 'socket='.$quote($config['unix_socket'])."\n";
        } else {
            $options .= 'host='.$quote($config['host'] ?? '127.0.0.1')."\n"
                .'port='.(int) ($config['port'] ?? 3306)."\n";
        }
        // MySQL の mysqldump は、GTID が OFF 以外(Amazon RDS の既定は OFF_PERMISSIVE)のサーバーでは
        // --single-transaction でも FLUSH TABLES WITH READ LOCK を実行しようとし、RELOAD 権限の無いユーザーではダンプに失敗する。
        // GTID の情報は復元に不要(別のサーバーに戻すと逆にエラーになる)なので出力しない。
        // MariaDB の mysqldump にはこのオプションが無い(付けるとエラーになる)ため、使えるときだけ付ける
        $gtidOption = $this->mysqldumpSupports($resolved, '--set-gtid-purged') ? ['--set-gtid-purged=OFF'] : [];

        file_put_contents($optionFile, $options);
        @chmod($optionFile, 0600);

        try {
            $result = Process::timeout(3600)->run([
                $resolved,
                '--defaults-extra-file='.$optionFile, // 必ず最初の引数にする(mysqldumpの仕様)
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--no-tablespaces', // 一般ユーザーでもダンプできるように(PROCESS権限が不要)
                ...$gtidOption,
                '--default-character-set=utf8mb4',
                '--result-file='.$target,
                (string) $config['database'],
            ]);
        } finally {
            @unlink($optionFile);
        }

        if (! $result->successful() || ! is_file($target)) {
            throw new RuntimeException('データベースのダンプに失敗しました: '.trim($result->errorOutput()));
        }

        return $target;
    }

    /**
     * mysqldump がそのオプションに対応しているか(--help の一覧に載っているか)
     */
    private function mysqldumpSupports(string $binary, string $option): bool
    {
        $result = Process::timeout(30)->run([$binary, '--help']);

        return $result->successful() && str_contains($result->output(), $option);
    }

    /**
     * ファイルをまとめる(zip拡張があれば zip、無ければ tar.gz)
     *
     * @param  array<string, string>  $entries  アーカイブ内のパス => 実ファイル
     */
    private function archive(string $basePath, array $entries): string
    {
        if (class_exists(ZipArchive::class)) {
            $target = $basePath.'.zip';
            $zip = new ZipArchive;
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("バックアップファイル {$target} を作成できませんでした。");
            }
            foreach ($entries as $name => $file) {
                $zip->addFile($file, $name);
            }
            if (! $zip->close()) {
                throw new RuntimeException("バックアップファイル {$target} の書き込みに失敗しました。");
            }

            return $target;
        }

        $tar = new PharData($basePath.'.tar');
        foreach ($entries as $name => $file) {
            $tar->addFile($file, $name);
        }
        $tar->compress(Phar::GZ);
        unset($tar);
        @unlink($basePath.'.tar');

        return $basePath.'.tar.gz';
    }

    /**
     * バックアップに同梱する復元手順
     */
    private function restoreInstructions(string $databaseFile, bool $includeEnv): string
    {
        $app = config('app.name');
        $created = now()->format('Y/m/d H:i');
        $database = $databaseFile === 'database.sql'
            ? "   MySQL : mysql -u ユーザー名 -p データベース名 < database.sql\n          (空のデータベースに戻すのが安全です)"
            : '   SQLite: database.sqlite を database/database.sqlite に上書きコピー';
        $env = $includeEnv
            ? '1. 同梱の .env をインストール先に置く(APP_KEY を元と同じにするため。DB接続先などが変わる場合は書き換える)'
            : '1. 元の環境と同じ APP_KEY を .env に設定する(このバックアップには .env は含まれていません)';

        return <<<TXT
        {$app} バックアップ（{$created} 作成）

        ■ 復元手順
        0. 新しいサーバーに戻す場合は、先に同じバージョンのアプリを設置して composer install まで済ませる
        {$env}
        2. データベースを戻す
        {$database}
        3. 添付ファイルを戻す
           attachments フォルダを storage/app/private/attachments に上書きコピー
        4. php artisan migrate --force（新しいバージョンのアプリに戻した場合のため）
        5. php artisan optimize:clear

        ■ 注意
        このファイルにはお客様の課題・添付ファイル、および .env（パスワード等）が含まれます。
        第三者がアクセスできない場所に保管してください。

        TXT;
    }
}
