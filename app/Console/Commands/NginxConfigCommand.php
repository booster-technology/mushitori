<?php

namespace App\Console\Commands;

use App\Services\InstallerService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * このサーバー用のNginx設定(deploy/nginx の設定例を、URL・インストール先・PHP-FPMのソケットに合わせたもの)を表示する。
 * サーバー設定スクリプト(deploy/setup-server.sh)が、/etc/nginx/conf.d に置く内容を作るのに使う(ファイルには書き込まない)
 */
class NginxConfigCommand extends Command
{
    protected $signature = 'app:nginx-config
        {--url= : 公開URL(省略時は .env の APP_URL)}
        {--behind-proxy : ロードバランサー(ALB等)の後ろで動かす(省略時は .env に TRUSTED_PROXIES があれば)}';

    protected $description = 'このサーバー用のNginx設定を表示する(deploy/setup-server.sh で使う)';

    public function handle(InstallerService $installer): int
    {
        // URLは指定が無ければ .env の APP_URL(インストール前で .env が無い場合は指定が必要)
        $url = rtrim((string) ($this->option('url') ?: (is_file(app()->environmentFilePath()) ? config('app.url') : '')), '/');
        if ($url === '') {
            $this->error('公開URLを --url=https://issues.example.com のように指定してください。');

            return self::FAILURE;
        }
        // ドメインはNginxの設定(server_name)にそのまま入るため、「;」などで別の設定を紛れ込ませられないよう英数字・ドット・ハイフンに限る
        $error = InstallerService::validateUrl($url) ?? InstallerService::validateHost((string) parse_url($url, PHP_URL_HOST));
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $behindProxy = $this->option('behind-proxy') || ! empty(config('trustedproxy.proxies'));
        $config = $installer->nginxConfig([
            'url' => $url,
            'trusted_proxies' => $behindProxy ? InstallerService::PRIVATE_NETWORKS : null,
        ]);
        // そのまま設定ファイルにするので、装飾(<info> 等のタグの解釈)をせずに1行ずつ出す
        foreach (explode("\n", rtrim($config, "\n")) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
