<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Installer\EnvFile;
use App\Support\Installer\PhpFpmUser;
use App\Support\Installer\RequirementChecker;
use App\Support\ThemeColor;
use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * インストール処理の本体(コマンド版インストーラ php artisan app:install から使う)。
 * .env の書き込み・DBの作成・テーブル作成・最初の社内メンバーの登録・テストメール・サーバー設定の案内を行う
 */
class InstallerService
{
    /** 社内用URLに使えない名前(お客様用の画面と衝突するもの) */
    /**
     * 「ロードバランサーの後ろで動かす」場合に信頼する接続元(プライベートIPアドレスの範囲。ALBはVPC内のプライベートIPから接続してくる)。
     * ※ * は Laravel では「すべてのIPを信頼」になり、X-Forwarded-For の偽装で接続元IPを変えられてしまうため使わない
     */
    public const PRIVATE_NETWORKS = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

    /**
     * 「Amazon SES(IAMロール)」を選んだときのメールの送り方(Laravel の ses-v2。SES API v2 で送信する)。
     * .env にアクセスキーを書かなければ、AWS SDK がサーバー(EC2)のIAMロールの認証情報を自動で使うので、SMTPの設定は不要
     */
    public const SES_MAILER = 'ses-v2';

    public const RESERVED_PATHS = ['login', 'logout', 'projects', 'issues', 'comments', 'attachments', 'up', 'css', 'js', 'build', 'storage', 'install', 'help'];

    /**
     * 動作環境のチェック結果(PHPのバージョン・拡張モジュール・書き込み権限)
     *
     * @return list<array{label: string, ok: bool, hint: string}>
     */
    public function requirements(): array
    {
        return (new RequirementChecker(base_path()))->check();
    }

    /**
     * @return list<string>
     */
    public function availableDatabases(): array
    {
        return (new RequirementChecker(base_path()))->availableDatabases();
    }

    /**
     * SQLiteを選んだ場合のファイルの場所(既定は database/database.sqlite)
     */
    public function defaultSqlitePath(): string
    {
        return (string) (config('installer.sqlite_path') ?: database_path('database.sqlite'));
    }

    /**
     * MySQLに接続し、データベースがあるか確認する
     *
     * @param  array<string, mixed>  $db
     * @return array{error: ?string, exists: bool}
     */
    public function checkMysql(array $db): array
    {
        try {
            $pdo = $this->mysqlConnection($db);
            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->execute([$db['db_database']]);

            return ['error' => null, 'exists' => $stmt->fetchColumn() !== false];
        } catch (PDOException $e) {
            $this->logSafely('warning', 'インストーラ: MySQLに接続できませんでした', ['host' => $db['db_host'], 'port' => $db['db_port'], 'user' => $db['db_username'], 'error' => $e->getMessage()]);

            return ['error' => 'MySQLに接続できませんでした: '.$e->getMessage(), 'exists' => false];
        }
    }

    /**
     * MySQLに接続できるか確認し、データベースが無ければ(許可されていれば)作成する。
     * 問題があれば、どの項目のエラーかとメッセージを返す
     *
     * @param  array<string, mixed>  $db
     * @return array{field: string, message: string}|null
     */
    public function prepareMysql(array $db, bool $createIfMissing): ?array
    {
        $check = $this->checkMysql($db);
        if ($check['error'] !== null) {
            return ['field' => 'db_host', 'message' => $check['error']];
        }
        if ($check['exists']) {
            return null;
        }

        if (! $createIfMissing) {
            return [
                'field' => 'db_database',
                'message' => "データベース「{$db['db_database']}」がありません。作成してから再度お試しいただくか、「データベースが無ければ作成する」にチェックを入れてください。",
            ];
        }

        $error = $this->createMysqlDatabase($db);

        return $error === null ? null : ['field' => 'db_database', 'message' => $error];
    }

    /**
     * MySQLのデータベースを作成する。失敗した場合はエラーメッセージを返す
     *
     * @param  array<string, mixed>  $db
     */
    public function createMysqlDatabase(array $db): ?string
    {
        if (self::validateDatabaseName((string) $db['db_database']) !== null) {
            return 'データベース名が正しくありません。';
        }

        try {
            // データベース名は英数字と _ のみに制限済み
            $this->mysqlConnection($db)->exec("CREATE DATABASE `{$db['db_database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return null;
        } catch (PDOException $e) {
            $this->logSafely('warning', 'インストーラ: データベースを作成できませんでした', ['database' => $db['db_database'], 'user' => $db['db_username'], 'error' => $e->getMessage()]);

            return "データベースを作成できませんでした（このユーザーに作成権限が無い可能性があります）: {$e->getMessage()}";
        }
    }

    /**
     * インストールを実行する: .env 書き込み → テーブル作成 → 最初の社内メンバー登録 → (本番)Nginx設定生成 → テストメール
     *
     * @param  array<string, mixed>  $s  設定(キーは InstallCommand を参照)
     * @param  Closure(string): void|null  $progress  進み具合の通知(コマンド版の画面表示用)
     * @return array{admin: User, nginx: ?string, mail_error: ?string}
     */
    public function install(array $s, ?Closure $progress = null, ?OutputInterface $migrationOutput = null): array
    {
        $progress ??= fn (string $message) => null;
        $this->assertSafeSettings($s);

        $env = EnvFile::createFromExample(app()->environmentFilePath(), base_path('.env.example'));
        $this->writeEnv($env, $s);
        $progress('設定ファイル(.env)を書き込みました');

        $this->applyRuntimeConfig($s, (string) $env->get('APP_KEY'));
        $this->migrate($s, $migrationOutput);
        $progress('データベースのテーブルを作成しました');

        $admin = User::updateOrCreate(
            ['email' => $s['admin_email']],
            ['name' => $s['admin_name'], 'role' => UserRole::Staff->value, 'company_name' => null, 'is_active' => true]
        );
        $progress("社内メンバー「{$admin->name}」<{$admin->email}> を登録しました");

        $nginx = null;
        if (($s['generate_nginx'] ?? false) === true) {
            $nginx = $this->writeNginxConfig($s);
            $progress('Nginxの設定ファイルを生成しました: '.$nginx);
        }

        $mailError = $this->sendTestMail($s, $admin);
        if (in_array($s['mail'], ['smtp', 'ses'], true) && $mailError === null) {
            $progress("テストメールを {$admin->email} に送信しました");
        }

        return ['admin' => $admin, 'nginx' => $nginx, 'mail_error' => $mailError];
    }

    /**
     * 完了後に案内する「次にやること」
     *
     * @param  array<string, mixed>  $s
     * @return list<string>
     */
    public function nextSteps(array $s, ?string $nginxPath): array
    {
        $steps = [];
        $base = str_replace('\\', '/', base_path());

        if ($s['mode'] === 'production') {
            // 権限・cron の案内には、Webサーバー(PHP-FPM)の実際の実行ユーザーを出す(コマンド版はSSHのユーザーで動くため、PHP-FPMの www.conf から調べる。分からなければ仮の文字)
            $account = PhpFpmUser::detect();
            $webUser = $account['user'] ?? '<Webサーバーの実行ユーザー>';
            $owner = $account !== null ? "{$account['user']}:{$account['group']}" : $webUser;

            // まとめて設定するスクリプト(行う内容を表示して確認してから実行する)
            $steps[] = "VPS・専用サーバーでは、書き込み権限・Nginx・cron の設定を sudo bash {$base}/deploy/setup-server.sh でまとめて行えます"
                .'(行う内容を表示し、確認してから実行します)。手で行う場合やレンタルサーバーの場合は、以下のとおりです';
            if ($nginxPath !== null) {
                $steps[] = "Nginxの設定: {$nginxPath} を /etc/nginx/conf.d/ にコピーし、sudo nginx -t && sudo systemctl reload nginx";
                $apacheExample = ! empty($s['trusted_proxies']) ? 'mushitori-alb.conf' : (str_starts_with($s['url'], 'https://') ? 'mushitori.conf' : 'mushitori-http.conf');
                $steps[] = "Apacheを使う場合: deploy/apache/{$apacheExample} を参考に設定し、sudo apachectl configtest で確認してから再起動";
            }
            $steps[] = 'PHPの設定(アップロード上限など): public/.user.ini で自動的に設定されます(php-fpm が読み込みます)。'
                .'サーバー全体で設定する場合は deploy/php/99-mushitori.ini を php.ini の追加設定として配置し、php-fpm を再起動';
            // インストール中に作られたログ等はSSHのユーザーの所有になるため、インストールの後に実行する
            $steps[] = "書き込み権限: cd {$base} && sudo chown -R {$owner} storage bootstrap/cache".($s['db'] === 'sqlite' ? ' database' : '');
            // cron はWebサーバー(PHP-FPM)と同じユーザーで動かす(別のユーザーだと、cron が作ったログファイルにWeb側が書き込めずエラーになる)
            $steps[] = "定期実行(cron): Webサーバー(PHP)と同じユーザーで登録してください(例: sudo crontab -u {$webUser} -e)。"
                ."登録する内容: * * * * * cd {$base} && php artisan schedule:run >> /dev/null 2>&1"
                .'(レンタルサーバーでは、コントロールパネルのcron設定で php の部分をPHPのフルパス(例: /usr/bin/php8.3)にして登録してください)';
            $steps[] = $s['backup_daily']
                ? 'バックアップ: 毎日3時に storage/backups へ保存されます(上のcronが必要)。サーバー故障に備えて別の場所へのコピーもおすすめします'
                : 'バックアップ: php artisan app:backup で作成できます(.env の BACKUP_DAILY=true で毎日自動実行)';
            if (! empty($s['trusted_proxies'])) {
                $steps[] = 'ロードバランサー: ターゲットグループのヘルスチェックのパスを /up に、アイドルタイムアウトを120秒にし(画像をまとめて添付した投稿は60秒以上かかることがあるため)、このサーバーへの接続はロードバランサーからだけ許可してください(セキュリティグループ等で制限)';
            } elseif (str_starts_with($s['url'], 'http://')) {
                $steps[] = 'インターネットに公開する場合は HTTPS(SSL)を設定してください(Let\'s Encrypt なら無料)';
            }
        } else {
            // 入力されたURLのホスト・ポートで起動する(localhost以外ならLAN内の他のPCからも開けるよう 0.0.0.0)
            $host = (string) parse_url($s['url'], PHP_URL_HOST);
            $port = parse_url($s['url'], PHP_URL_PORT) ?: 8000;
            $listen = in_array($host, ['localhost', '127.0.0.1'], true) ? $host : '0.0.0.0';
            $steps[] = "開発サーバーの起動: php -d upload_max_filesize=20M -d post_max_size=120M -S {$listen}:{$port} -t public scripts/dev-router.php";
        }

        if ($s['mail'] === 'ses') {
            $steps[] = "Amazon SES: このサーバー(EC2)に、SESでメールを送信できるIAMロール(ses:SendEmail・ses:SendRawEmail を許可)を付けてください。送信元 {$s['mail_from']} は、SESで認証済みのドメインかメールアドレスにしてください(SESのサンドボックス中は、認証済みのアドレスにしか送れません)";
        }

        if ($s['mail'] === 'log') {
            $steps[] = 'メールを送信しない設定のため、ログイン用の認証コードは storage/logs/laravel-日付.log に出力されます(php scripts/watch-login-codes.php で表示できます。メール送信の設定は後から .env の MAIL_ で変更できます)';
        }

        return $steps;
    }

    /**
     * 本番用のNginx設定ファイルを、入力されたURL・インストール先に合わせて生成する
     *
     * @param  array<string, mixed>  $s
     */
    public function writeNginxConfig(array $s): string
    {
        $path = storage_path('app/install/nginx.conf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $this->nginxConfig($s));

        return $path;
    }

    /**
     * 本番用のNginx設定の内容。deploy/nginx の設定例を元に、URL(ドメイン)・インストール先・PHP-FPMのソケットを書き換える
     * (コマンド版インストーラと、サーバー設定スクリプト deploy/setup-server.sh(php artisan app:nginx-config 経由)で使う)
     *
     * @param  array{url: string, trusted_proxies?: mixed}  $s
     */
    public function nginxConfig(array $s): string
    {
        $https = str_starts_with($s['url'], 'https://');
        $template = match (true) {
            ! empty($s['trusted_proxies']) => 'mushitori-alb.conf',   // HTTPSはロードバランサーで終端
            $https => 'mushitori.conf',
            default => 'mushitori-http.conf',
        };
        $config = (string) file_get_contents(base_path('deploy/nginx/'.$template));

        $socket = collect([
            '/run/php/php8.4-fpm.sock',
            '/run/php/php8.3-fpm.sock',
            '/run/php-fpm/www.sock',
            '/var/run/php-fpm/www.sock',
        ])->first(fn (string $path) => file_exists($path));

        $config = str_replace(
            ['issues.example.com', '/var/www/mushitori'],
            [(string) parse_url($s['url'], PHP_URL_HOST), str_replace('\\', '/', base_path())],
            $config
        );
        if ($socket !== null) {
            $config = str_replace('unix:/run/php-fpm/www.sock', 'unix:'.$socket, $config);
        }

        return $config;
    }

    // ---------------------------------------------------------------
    // 入力値のチェック(コマンド版インストーラ・app:nginx-config で使う)。問題があればメッセージ、無ければ null
    // ---------------------------------------------------------------

    public static function validateUrl(string $value): ?string
    {
        return preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', trim($value)) ? null : 'http:// または https:// で始まるURLを入力してください。';
    }

    public static function validateEmail(string $value): ?string
    {
        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? null : 'メールアドレスの形式で入力してください。';
    }

    /**
     * AWSのリージョン(例: ap-northeast-1)
     */
    public static function validateRegion(string $value): ?string
    {
        return preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d{1,2}$/', $value) ? null : 'リージョンは ap-northeast-1 のような形式で入力してください。';
    }

    public static function validateDatabaseName(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) ? null : 'データベース名は英数字と _ で入力してください。';
    }

    /**
     * DB・SMTPのホスト名(英数字・ドット・ハイフンのホスト名、またはIPアドレス)。
     * 「;」などを含めて接続文字列に別の設定(unix_socket など)を紛れ込ませられないようにする
     */
    public static function validateHost(string $value): ?string
    {
        $valid = preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$/', $value) === 1
            || filter_var(trim($value, '[]'), FILTER_VALIDATE_IP) !== false;

        return $valid ? null : 'ホスト名は英数字・ドット・ハイフン、またはIPアドレスで入力してください。';
    }

    public static function validateStaffPath(string $value): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-]{2,39}$/', $value)) {
            return '英小文字・数字・ハイフンで3〜40文字にしてください。';
        }

        return in_array($value, self::RESERVED_PATHS, true) ? 'その名前はお客様用の画面と重なるため使えません。' : null;
    }

    // ---------------------------------------------------------------

    /**
     * .env を書き込む。APP_KEY(暗号鍵)は未設定の場合のみ新しく作る(作り直すと既存データが読めなくなるため)
     *
     * @param  array<string, mixed>  $s
     */
    private function writeEnv(EnvFile $env, array $s): void
    {
        $isProduction = $s['mode'] === 'production';

        if (blank($env->get('APP_KEY'))) {
            $env->set('APP_KEY', $this->generateKey());
        }

        $env->set('APP_NAME', $s['name'])
            ->set('APP_ENV', $s['mode'])
            ->set('APP_DEBUG', ! $isProduction)
            ->set('APP_URL', $s['url'])
            ->set('APP_THEME_COLOR', $s['theme_color'])
            ->set('APP_TIMEZONE', 'Asia/Tokyo')
            ->set('APP_LOCALE', 'ja')
            ->set('STAFF_PATH', $s['staff_path'])
            ->set('INITIAL_STAFF_EMAIL', $s['admin_email'])
            ->set('INITIAL_STAFF_NAME', $s['admin_name'])
            // メールを送信しない設定(log)では、メールの内容(認証コード)が debug レベルでログに出るため、本番でも debug にする
            ->set('LOG_LEVEL', $isProduction && $s['mail'] !== 'log' ? 'info' : 'debug')
            ->set('SESSION_SECURE_COOKIE', str_starts_with($s['url'], 'https://'))
            ->set('TRUSTED_PROXIES', (string) ($s['trusted_proxies'] ?? ''))
            ->set('BACKUP_DAILY', (bool) $s['backup_daily'])
            ->set('DB_CONNECTION', $s['db']);

        if ($s['db'] === 'mysql') {
            $env->set('DB_HOST', $s['db_host'])
                ->set('DB_PORT', $s['db_port'])
                ->set('DB_DATABASE', $s['db_database'])
                ->set('DB_USERNAME', $s['db_username'])
                ->set('DB_PASSWORD', $s['db_password']);
        } elseif ($s['sqlite_path'] === database_path('database.sqlite')) {
            // 既定の場所なら書かない(フォルダごと移動しても動くように)
            $env->remove('DB_DATABASE');
        } else {
            $env->set('DB_DATABASE', $s['sqlite_path']);
        }

        $env->set('MAIL_MAILER', $s['mail'] === 'ses' ? self::SES_MAILER : $s['mail']);
        if ($s['mail'] === 'ses') {
            // アクセスキーは書かない(サーバーのIAMロールの認証情報を使う)
            $env->set('AWS_DEFAULT_REGION', $s['ses_region'])
                ->set('MAIL_FROM_ADDRESS', $s['mail_from']);
        }
        if ($s['mail'] === 'smtp') {
            $env->set('MAIL_HOST', $s['mail_host'])
                ->set('MAIL_PORT', $s['mail_port'])
                ->set('MAIL_SCHEME', $s['mail_encryption'] === 'ssl' ? 'smtps' : 'smtp')
                ->set('MAIL_USERNAME', $s['mail_username'] === '' ? 'null' : $s['mail_username'])
                ->set('MAIL_PASSWORD', $s['mail_password'] === '' ? 'null' : $s['mail_password'])
                ->set('MAIL_FROM_ADDRESS', $s['mail_from']);
        }
        $env->set('MAIL_FROM_NAME', $s['name']);

        $env->save();
    }

    /**
     * 書き込んだ設定を、実行中の処理にも反映する(続けてDB作成・メール送信を行うため)
     *
     * @param  array<string, mixed>  $s
     */
    private function applyRuntimeConfig(array $s, string $appKey): void
    {
        config([
            'app.key' => $appKey,
            'app.name' => $s['name'],
            'app.url' => $s['url'],
            'app.theme_color' => $s['theme_color'],
            'app.staff_path' => $s['staff_path'],
            'mail.default' => $s['mail'] === 'ses' ? self::SES_MAILER : $s['mail'],
            'mail.from.address' => $s['mail_from'] ?? config('mail.from.address'),
            'mail.from.name' => $s['name'],
        ]);

        $this->applyDatabaseConfig($s);

        if ($s['mail'] === 'ses') {
            config(['services.ses.region' => $s['ses_region']]);
        }

        if ($s['mail'] === 'smtp') {
            config([
                'mail.mailers.smtp.host' => $s['mail_host'],
                'mail.mailers.smtp.port' => (int) $s['mail_port'],
                'mail.mailers.smtp.scheme' => $s['mail_encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => $s['mail_username'] ?: null,
                'mail.mailers.smtp.password' => $s['mail_password'] ?: null,
            ]);
        }

        URL::forceRootUrl($s['url']);
    }

    /**
     * 入力されたデータベースの接続情報を、実行中の処理に反映する
     *
     * @param  array<string, mixed>  $s
     */
    private function applyDatabaseConfig(array $s): void
    {
        config(['database.default' => $s['db']]);

        if ($s['db'] === 'mysql') {
            config([
                'database.connections.mysql.host' => $s['db_host'],
                'database.connections.mysql.port' => $s['db_port'],
                'database.connections.mysql.database' => $s['db_database'],
                'database.connections.mysql.username' => $s['db_username'],
                'database.connections.mysql.password' => $s['db_password'],
            ]);
        } else {
            config(['database.connections.sqlite.database' => $s['sqlite_path']]);
        }

        DB::purge();
        DB::setDefaultConnection($s['db']);
    }

    /**
     * 設定値に改行などの制御文字が含まれていないか、ホスト名が正しい形式かを確認する
     * (.env やバックアップ時の mysqldump の設定ファイルに、別の設定を紛れ込ませられないように)
     *
     * @param  array<string, mixed>  $s
     */
    private function assertSafeSettings(array $s): void
    {
        foreach ($s as $key => $value) {
            if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new RuntimeException("設定値（{$key}）に改行などの制御文字は使えません。");
            }
        }
        foreach (['db_host', 'mail_host'] as $key) {
            if (isset($s[$key]) && ($error = self::validateHost((string) $s[$key])) !== null) {
                throw new RuntimeException($error);
            }
        }
    }

    /**
     * テーブルを作成する(既存のデータは消さない)
     *
     * @param  array<string, mixed>  $s
     */
    private function migrate(array $s, ?OutputInterface $output): void
    {
        if ($s['db'] === 'sqlite' && ! is_file($s['sqlite_path'])) {
            if (! is_dir(dirname($s['sqlite_path'])) || ! @touch($s['sqlite_path'])) {
                throw new RuntimeException("SQLiteのファイル {$s['sqlite_path']} を作成できませんでした。フォルダの書き込み権限を確認してください。");
            }
        }

        $buffer = $output ?? new BufferedOutput;
        $exitCode = Artisan::call('migrate', ['--force' => true], $buffer);
        if ($exitCode !== 0) {
            $detail = $buffer instanceof BufferedOutput ? ' '.trim($buffer->fetch()) : '';
            throw new RuntimeException('テーブルの作成に失敗しました。'.$detail);
        }
    }

    /**
     * SMTPを設定した場合は、管理者宛てにテストメール(ログインURLの案内)を送る。失敗した場合はエラーメッセージを返す
     *
     * @param  array<string, mixed>  $s
     */
    private function sendTestMail(array $s, User $admin): ?string
    {
        if (! in_array($s['mail'], ['smtp', 'ses'], true)) {
            return null;
        }

        $body = "{$s['name']} のインストールが完了しました。\n\n"
            ."社内メンバー用ログイン: {$s['url']}/{$s['staff_path']}/login\n"
            ."お客様用ログイン: {$s['url']}/login\n\n"
            .'このメールが届いていれば、メール送信の設定は正しく動いています。';

        try {
            Mail::raw($body, fn ($m) => $m->to($admin->email)->subject("【{$s['name']}】インストール完了のお知らせ（テストメール）"));

            return null;
        } catch (Throwable $e) {
            Log::error('インストーラ: テストメールの送信に失敗しました', ['to' => $admin->email, 'mail' => $s['mail'], 'mail_host' => $s['mail_host'] ?? null, 'exception' => $e]);

            return $s['mail'] === 'ses'
                ? 'テストメールの送信に失敗しました。このサーバーのIAMロール(SESの送信権限)・.env の AWS_DEFAULT_REGION・送信元アドレス(SESで認証済みか)を確認してください: '.$e->getMessage()
                : 'テストメールの送信に失敗しました。.env の MAIL_ で始まる設定を確認してください: '.$e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $db
     */
    private function mysqlConnection(array $db): PDO
    {
        if (self::validateHost((string) $db['db_host']) !== null) {
            throw new PDOException('ホスト名の形式が正しくありません。');
        }

        return new PDO(
            'mysql:host='.$db['db_host'].';port='.(int) $db['db_port'].';charset=utf8mb4',
            (string) $db['db_username'],
            (string) ($db['db_password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    }

    /**
     * ログを出す。インストール前は storage/logs に書けないこともあるので、ログ出力自体の失敗では処理を止めない
     *
     * @param  array<string, mixed>  $context
     */
    private function logSafely(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // 書き込めない場合は、インストーラの「動作環境のチェック」で storage の権限エラーとして案内される
        }
    }

    private function generateKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
    }

    /**
     * テーマカラーの入力(プリセット名またはカラーコード)を #RRGGBB にする
     */
    public static function resolveThemeColor(?string $value): ?string
    {
        return ThemeColor::resolve($value);
    }
}
