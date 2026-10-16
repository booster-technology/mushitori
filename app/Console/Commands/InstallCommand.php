<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InstallerService;
use App\Support\Installer\EnvFile;
use App\Support\ThemeColor;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * 対話形式のインストーラ(通常は install.sh から実行する。インストールは SSH でコマンドを実行して行う)。
 * 動作環境チェック → 設定の聞き取り → 確認 の後、実際の処理は InstallerService が行う。
 * すべての項目はオプションでも指定でき、--no-interaction と組み合わせると自動インストールにも使える。
 */
class InstallCommand extends Command
{
    protected $signature = 'app:install
        {--mode= : production(本番サーバー) または local(お試し・開発)}
        {--name= : サイト名}
        {--url= : 公開URL(例: https://issues.example.com)}
        {--theme-color= : テーマカラー(navy/green/blue/red/purple/gray または #0D223A のようなカラーコード)}
        {--db= : sqlite または mysql}
        {--db-host= : MySQLのホスト}
        {--db-port= : MySQLのポート}
        {--db-database= : MySQLのデータベース名}
        {--db-username= : MySQLのユーザー名}
        {--db-password= : MySQLのパスワード}
        {--sqlite-path= : SQLiteのファイルの場所(省略時は database/database.sqlite)}
        {--mail= : smtp(SMTPで送信) / ses(Amazon SES。EC2のIAMロールで送信) / log(送信せずログに出力)}
        {--ses-region= : Amazon SESのリージョン(例: ap-northeast-1)}
        {--mail-host= : SMTPサーバー}
        {--mail-port= : SMTPのポート}
        {--mail-username= : SMTPのユーザー名}
        {--mail-password= : SMTPのパスワード}
        {--mail-encryption= : tls / ssl / none}
        {--mail-from= : 送信元メールアドレス}
        {--staff-path= : 社内メンバー用画面のURL(例: staff → /staff/login)}
        {--admin-name= : 最初の社内メンバーの氏名}
        {--admin-email= : 最初の社内メンバーのメールアドレス}
        {--backup= : 毎日自動でバックアップするか(yes/no。本番のみ)}
        {--behind-proxy= : ロードバランサー(AWS ALB等)の後ろで動かすか(yes/no。本番のみ)}
        {--force : インストール済みでも実行する}';

    protected $description = 'ムシトリをインストールする(対話形式)';

    private InstallerService $installer;

    public function handle(InstallerService $installer): int
    {
        $this->installer = $installer;
        intro(' ムシトリ インストーラ ');

        try {
            // 1. 動作環境のチェック
            if (! $this->checkRequirements()) {
                return self::FAILURE;
            }

            $env = EnvFile::createFromExample($this->laravel->environmentFilePath(), base_path('.env.example'));

            // 2. インストール済みなら何もしない(誤って再実行してもデータや暗号鍵を壊さないように)
            if (! $this->option('force') && $this->alreadyInstalled()) {
                warning('既にインストールされています。設定を変更する場合は .env を直接編集するか、--force を付けて実行してください。');

                return self::SUCCESS;
            }

            // 3. 設定の聞き取り
            $settings = $this->collectSettings($env);

            $this->showSummary($settings);
            if (! confirm('この内容でインストールしますか？', default: true, yes: 'はい', no: 'いいえ')) {
                warning('インストールを中止しました。');

                return self::FAILURE;
            }

            // 4. インストールの実行(本番はNginxの設定ファイルも生成する)
            info('インストールしています…');
            $result = $installer->install(
                $settings + ['generate_nginx' => $settings['mode'] === 'production'],
                fn (string $message) => $this->line('  <fg=green>✔</> '.$message),
                $this->output
            );
            if ($result['mail_error'] !== null) {
                warning($result['mail_error']);
            }

            $this->showNextSteps($settings, $result['nginx']);
        } catch (RuntimeException $e) {
            // 入力値の誤り・接続できない等の想定内のエラー(画面に表示した内容をログにも残す)
            error($e->getMessage());
            Log::warning('インストールを中断しました: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // 想定外のエラーはスタックトレースごとログに残す
            error('インストール中にエラーが発生しました: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 動作環境をチェックして結果を表示する
     */
    private function checkRequirements(): bool
    {
        info('1. 動作環境のチェック');
        $results = $this->installer->requirements();

        foreach ($results as $result) {
            $this->line(($result['ok'] ? '  <fg=green>✔</> ' : '  <fg=red>✘</> ').$result['label']);
        }

        $failed = array_filter($results, fn (array $r) => ! $r['ok']);
        if ($failed !== []) {
            error('動作環境が不足しています。以下を対応してから、もう一度実行してください。');
            foreach ($failed as $result) {
                $this->line('  ・'.$result['hint']);
            }

            return false;
        }

        return true;
    }

    /**
     * 現在の設定でDBに接続でき、社内メンバーが登録済みならインストール済みとみなす
     */
    private function alreadyInstalled(): bool
    {
        try {
            return Schema::hasTable('users') && User::staff()->exists();
        } catch (Throwable) {
            // インストール前はDBに接続できないのが普通なので、ログには残さない
            return false;
        }
    }

    /**
     * インストールに必要な設定を聞き取る(オプションで指定済みの項目は聞かない)
     *
     * @return array<string, mixed>
     */
    private function collectSettings(EnvFile $env): array
    {
        $s = [];

        info('2. 基本設定');
        $s['mode'] = $this->choose('mode', 'どこで使いますか？', [
            'production' => '本番サーバーで使う',
            'local' => 'お試し・開発用（自分のPCなど）',
        ], $env->get('APP_ENV') === 'production' ? 'production' : 'local');
        $isProduction = $s['mode'] === 'production';

        $s['name'] = $this->optionOr('name', fn () => text(
            label: 'サイト名（画面の左上やメールに表示されます）',
            default: $env->get('APP_NAME') ?: 'ムシトリ',
            required: '入力してください。',
            validate: fn ($v) => mb_strlen($v) > 50 ? '50文字以内で入力してください。' : null,
        ));

        $s['theme_color'] = $this->chooseThemeColor($env);

        $defaultUrl = $isProduction ? 'https://' : ($env->get('APP_URL') ?: 'http://127.0.0.1:8000');
        $s['url'] = rtrim((string) $this->optionOr('url', fn () => text(
            label: '公開URL（ブラウザで開くときのURL）',
            placeholder: 'https://issues.example.com',
            default: $defaultUrl === 'https://' ? '' : $defaultUrl,
            required: '入力してください。',
            validate: fn ($v) => InstallerService::validateUrl($v),
            hint: '例: https://issues.example.com　ポート番号がある場合は http://192.168.1.10:8080 のように入力',
        ), fn ($v) => InstallerService::validateUrl($v)), '/');
        if ($isProduction && str_starts_with($s['url'], 'http://')) {
            warning('本番環境ではHTTPS(SSL)での公開を強くおすすめします（Let\'s Encryptなどで無料で設定できます）。');
        }

        // ロードバランサー(ALB等)でHTTPSを終端する構成なら、プライベートIPアドレスからの接続(=ロードバランサー)を信頼する(本番のみ)
        $s['trusted_proxies'] = '';
        if ($isProduction) {
            $behindProxy = $this->choose('behind-proxy', 'ロードバランサー（AWSのALBなど）の後ろで動かしますか？', [
                'no' => 'いいえ（このサーバーでHTTPSを受ける）',
                'yes' => 'はい（HTTPSはロードバランサーで終端し、このサーバーへはHTTPで転送）',
            ], 'no') === 'yes';
            $s['trusted_proxies'] = $behindProxy ? InstallerService::PRIVATE_NETWORKS : '';
        }

        info('3. データベース');
        // 本番はMySQL、お試しはSQLiteを初期選択にする(PHP拡張が無いものは選択肢から外す)
        $databases = $this->installer->availableDatabases();
        $preferred = $isProduction ? 'mysql' : 'sqlite';
        $s['db'] = $this->choose('db', 'データベースの種類', array_intersect_key([
            'mysql' => 'MySQL（本番向け・おすすめ）',
            'sqlite' => 'SQLite（設定不要・少人数向け）',
        ], array_flip($databases)), in_array($preferred, $databases, true) ? $preferred : $databases[0]);

        if ($s['db'] === 'mysql') {
            $s += $this->collectMysql($env);
        } else {
            $s['sqlite_path'] = $this->option('sqlite-path') ?: $this->installer->defaultSqlitePath();
        }

        info('4. メール送信（ログイン用の認証コード・通知メールに使います）');
        $s['mail'] = $this->choose('mail', 'メールの送り方', [
            'smtp' => 'SMTPサーバーで送信する（本番はこちら）',
            'ses' => 'Amazon SES で送信する（EC2のIAMロールで送信。SMTPの設定は不要）',
            'log' => '送信しない（お試し用。メールの内容は storage/logs/laravel-日付.log に出力）',
        ], $isProduction ? 'smtp' : 'log');

        if ($s['mail'] === 'smtp') {
            $s += $this->collectSmtp($env, $s['url']);
        } elseif ($s['mail'] === 'ses') {
            $s += $this->collectSes($env, $s['url']);
        } elseif ($isProduction) {
            warning('メールを送信しない設定です。ログインには storage/logs/laravel-日付.log に出力される認証コードを確認する必要があります。');
        }

        info('5. 社内メンバー用の画面');
        $suggested = $isProduction ? 'staff-'.Str::lower(Str::random(6)) : ($env->get('STAFF_PATH') ?: 'staff');
        $s['staff_path'] = $this->optionOr('staff-path', fn () => text(
            label: '社内メンバー用ログイン画面のURL（/○○/login の ○○ の部分）',
            default: $suggested,
            required: '入力してください。',
            validate: fn ($v) => InstallerService::validateStaffPath($v),
            hint: '推測されにくい文字列にすると、お客様に社内用画面の存在を知られにくくなります',
        ), fn ($v) => InstallerService::validateStaffPath($v));

        $s['admin_name'] = $this->optionOr('admin-name', fn () => text(
            label: '最初の社内メンバー（管理者）の氏名',
            default: '管理者',
            required: '入力してください。',
        ));
        $s['admin_email'] = mb_strtolower(trim((string) $this->optionOr('admin-email', fn () => text(
            label: '最初の社内メンバー（管理者）のメールアドレス',
            placeholder: 'you@example.com',
            required: '入力してください。',
            validate: fn ($v) => InstallerService::validateEmail($v),
            hint: 'このアドレスに届く認証コードでログインします',
        ), fn ($v) => InstallerService::validateEmail($v))));

        // 本番は毎日の自動バックアップをおすすめする(お試し用はcronが無いので聞かない)
        $s['backup_daily'] = false;
        if ($isProduction) {
            info('6. バックアップ');
            $s['backup_daily'] = $this->choose('backup', '毎日自動でバックアップしますか？（深夜3時・7日分を storage/backups に保存）', [
                'yes' => 'する（おすすめ）',
                'no' => 'しない（手動で php artisan app:backup を実行）',
            ], 'yes') === 'yes';
        }

        return $s;
    }

    /**
     * テーマカラーを選んでもらう(プリセットから選ぶか、カラーコードを直接入力)
     */
    private function chooseThemeColor(EnvFile $env): string
    {
        $given = $this->option('theme-color');
        if ($given !== null && $given !== '') {
            return ThemeColor::resolve($given) ?? throw new RuntimeException(
                '--theme-color の値が正しくありません: '.implode(' / ', array_keys(ThemeColor::PRESETS)).' または #0D223A のようなカラーコードを指定してください。'
            );
        }

        $current = ThemeColor::normalize($env->get('APP_THEME_COLOR')) ?? ThemeColor::DEFAULT;
        $options = collect(ThemeColor::PRESETS)
            ->mapWithKeys(fn (array $preset, string $key) => [$key => "{$preset[1]}　{$preset[0]}"])
            ->put('custom', 'その他（カラーコードを入力）')
            ->all();
        $default = collect(ThemeColor::PRESETS)->search(fn (array $preset) => $preset[0] === $current) ?: 'custom';

        $choice = select(
            label: 'テーマカラー（画面のヘッダーやボタンの色）',
            options: $options,
            default: $default,
            hint: '後から .env の APP_THEME_COLOR で変更できます。明るい色を選んでも文字色は自動で読みやすく調整されます',
        );
        if ($choice !== 'custom') {
            return ThemeColor::PRESETS[$choice][0];
        }

        return (string) ThemeColor::normalize(text(
            label: 'カラーコード',
            placeholder: '#0D223A',
            default: $current,
            required: '入力してください。',
            validate: fn ($v) => ThemeColor::normalize($v) ? null : '#0D223A のような6桁のカラーコードで入力してください。',
        ));
    }

    /**
     * MySQLの接続情報を聞き取り、接続できるまで確認する
     *
     * @return array<string, string>
     */
    private function collectMysql(EnvFile $env): array
    {
        while (true) {
            $db = [
                'db_host' => (string) $this->optionOr('db-host', fn () => text('ホスト', default: $env->get('DB_HOST') ?: '127.0.0.1', required: '入力してください。')),
                'db_port' => (string) $this->optionOr('db-port', fn () => text('ポート', default: $env->get('DB_PORT') ?: '3306', required: '入力してください。')),
                'db_database' => (string) $this->optionOr('db-database', fn () => text(
                    label: 'データベース名',
                    default: $env->get('DB_DATABASE') ?: 'mushitori',
                    required: '入力してください。',
                    validate: fn ($v) => InstallerService::validateDatabaseName($v),
                ), fn ($v) => InstallerService::validateDatabaseName($v)),
                'db_username' => (string) $this->optionOr('db-username', fn () => text('ユーザー名', default: $env->get('DB_USERNAME') ?: 'mushitori', required: '入力してください。')),
                'db_password' => (string) $this->optionOr('db-password', fn () => password('パスワード')),
            ];

            $error = $this->prepareMysql($db);
            if ($error === null) {
                return $db;
            }

            error($error);
            // オプション指定・非対話の場合は聞き直せないので中断する
            if ($this->option('db-host') !== null || ! $this->input->isInteractive()) {
                throw new RuntimeException('MySQLの設定を確認してから、もう一度実行してください。');
            }
            if (! confirm('入力し直しますか？', default: true, yes: 'はい', no: 'いいえ')) {
                throw new RuntimeException('インストールを中止しました。');
            }
        }
    }

    /**
     * MySQLに接続できるか確認し、データベースが無ければ(確認のうえ)作成する。問題があればエラーメッセージを返す
     *
     * @param  array<string, string>  $db
     */
    private function prepareMysql(array $db): ?string
    {
        $check = $this->installer->checkMysql($db);
        if ($check['error'] !== null) {
            return $check['error'];
        }
        if ($check['exists']) {
            $this->line('  <fg=green>✔</> MySQLに接続できました');

            return null;
        }

        if (! confirm("データベース「{$db['db_database']}」がありません。作成しますか？", default: true, yes: 'はい', no: 'いいえ')) {
            return "データベース「{$db['db_database']}」を作成してから、もう一度実行してください。";
        }

        $error = $this->installer->createMysqlDatabase($db);
        if ($error === null) {
            $this->line("  <fg=green>✔</> データベース「{$db['db_database']}」を作成しました");
        }

        return $error;
    }

    /**
     * SMTPの設定を聞き取る
     *
     * @return array<string, string>
     */
    private function collectSmtp(EnvFile $env, string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $currentHost = $env->get('MAIL_HOST');

        $smtp = [
            'mail_host' => (string) $this->optionOr('mail-host', fn () => text(
                label: 'SMTPサーバー',
                placeholder: 'email-smtp.ap-northeast-1.amazonaws.com',
                default: $currentHost && $currentHost !== '127.0.0.1' ? $currentHost : '',
                required: '入力してください。',
            )),
            'mail_port' => (string) $this->optionOr('mail-port', fn () => text('ポート', default: '587', required: '入力してください。')),
        ];
        $smtp['mail_encryption'] = $this->choose('mail-encryption', '暗号化', [
            'tls' => 'STARTTLS（ポート587）',
            'ssl' => 'SSL/TLS（ポート465）',
            'none' => 'なし（ポート25など）',
        ], $smtp['mail_port'] === '465' ? 'ssl' : ($smtp['mail_port'] === '25' ? 'none' : 'tls'));
        $smtp['mail_username'] = (string) $this->optionOr('mail-username', fn () => text('ユーザー名（不要なら空欄）'));
        $smtp['mail_password'] = (string) $this->optionOr('mail-password', fn () => password('パスワード（不要なら空欄）'));
        $smtp['mail_from'] = (string) $this->optionOr('mail-from', fn () => text(
            label: '送信元メールアドレス',
            default: 'noreply@'.($host !== '' && ! filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost' ? $host : 'example.com'),
            required: '入力してください。',
            validate: fn ($v) => InstallerService::validateEmail($v),
        ), fn ($v) => InstallerService::validateEmail($v));

        return $smtp;
    }

    /**
     * Amazon SES(IAMロール)の設定を聞き取る。アクセスキー・SMTPの設定は不要で、リージョンと送信元だけ
     *
     * @return array<string, string>
     */
    private function collectSes(EnvFile $env, string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        note('このサーバー（EC2）に、SESでメールを送信できるIAMロールを付けておいてください。送信元はSESで認証済みのドメインかメールアドレスにします。');

        return [
            'ses_region' => (string) $this->optionOr('ses-region', fn () => text(
                label: 'SESのリージョン',
                default: $env->get('AWS_DEFAULT_REGION') ?: 'ap-northeast-1',
                required: '入力してください。',
                validate: fn ($v) => InstallerService::validateRegion($v),
            ), fn ($v) => InstallerService::validateRegion($v)),
            'mail_from' => (string) $this->optionOr('mail-from', fn () => text(
                label: '送信元メールアドレス（SESで認証済みのもの）',
                default: 'noreply@'.($host !== '' && ! filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost' ? $host : 'example.com'),
                required: '入力してください。',
                validate: fn ($v) => InstallerService::validateEmail($v),
            ), fn ($v) => InstallerService::validateEmail($v)),
        ];
    }

    /**
     * 確認用に設定内容を表示する(パスワードは伏せる)
     *
     * @param  array<string, mixed>  $s
     */
    private function showSummary(array $s): void
    {
        $rows = [
            ['用途', $s['mode'] === 'production' ? '本番' : 'お試し・開発'],
            ['サイト名', $s['name']],
            ['テーマカラー', $s['theme_color']],
            ['公開URL', $s['url']],
            ['データベース', $s['db'] === 'mysql'
                ? "MySQL {$s['db_username']}@{$s['db_host']}:{$s['db_port']}/{$s['db_database']}"
                : 'SQLite '.$s['sqlite_path']],
            ['メール', match ($s['mail']) {
                'smtp' => "SMTP {$s['mail_host']}:{$s['mail_port']}（送信元 {$s['mail_from']}）",
                'ses' => "Amazon SES {$s['ses_region']}（送信元 {$s['mail_from']}・サーバーのIAMロールで送信）",
                default => '送信しない（ログに出力）',
            }],
            ['社内用ログイン', $s['url'].'/'.$s['staff_path'].'/login'],
            ['お客様用ログイン', $s['url'].'/login'],
            ['最初の社内メンバー', "{$s['admin_name']} <{$s['admin_email']}>"],
            ['自動バックアップ', $s['backup_daily'] ? '毎日 3:00（7日分）' : 'なし'],
            ['ロードバランサー', $s['trusted_proxies'] !== '' ? 'あり（TRUSTED_PROXIES='.$s['trusted_proxies'].'）' : 'なし'],
        ];

        info('設定内容の確認');
        table(['項目', '内容'], $rows);
    }

    /**
     * 完了メッセージと次にやること
     *
     * @param  array<string, mixed>  $s
     */
    private function showNextSteps(array $s, ?string $nginxPath): void
    {
        outro(' インストールが完了しました ');

        $this->line('  社内メンバー用ログイン: <fg=cyan>'.$s['url'].'/'.$s['staff_path'].'/login</>（'.$s['admin_email'].' でログイン）');
        $this->line('  お客様用ログイン　　　: <fg=cyan>'.$s['url'].'/login</>');
        $this->newLine();

        $steps = $this->installer->nextSteps($s, $nginxPath);
        note("次にやること:\n".collect($steps)->map(fn ($step, $i) => ($i + 1).'. '.$step)->implode("\n"));
    }

    /**
     * 選択式の質問(オプション指定があればそれを使う)
     *
     * @param  array<string, string>  $options
     */
    private function choose(string $option, string $label, array $options, string $default): string
    {
        return (string) $this->optionOr(
            $option,
            fn () => select(label: $label, options: $options, default: $default),
            fn ($v) => array_key_exists($v, $options) ? null : '指定できる値: '.implode(' / ', array_keys($options))
        );
    }

    /**
     * オプションで値が指定されていればそれを(検証して)使い、無ければ質問する
     */
    private function optionOr(string $option, Closure $prompt, ?Closure $validate = null): mixed
    {
        $given = $this->option($option);
        if ($given === null || $given === '') {
            return $prompt();
        }

        $message = $validate ? $validate($given) : null;
        if ($message !== null) {
            throw new RuntimeException("--{$option} の値が正しくありません: {$message}");
        }

        return $given;
    }
}
