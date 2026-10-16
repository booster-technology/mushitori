<?php

namespace App\Support\Installer;

/**
 * Webサーバー(PHP-FPM)の実行ユーザー・グループを、PHP-FPMの設定ファイル(www.conf)から調べる。
 * コマンド版インストーラはSSHのユーザーで動くため、完了時の案内(書き込み権限・cron)に実際のユーザー名を出すのに使う
 */
class PhpFpmUser
{
    /** PHP-FPMの設定ファイルの場所(RHEL系・Amazon Linux / Debian系・Ubuntu / 公式Dockerイメージ等) */
    public const DEFAULT_PATTERNS = [
        '/etc/php-fpm.d/www.conf',
        '/etc/php/*/fpm/pool.d/www.conf',
        '/usr/local/etc/php-fpm.d/www.conf',
    ];

    /**
     * 最初に見つかった設定ファイルの user・group(group が無ければ user と同じ)。見つからなければ null
     *
     * @param  list<string>|null  $patterns  調べる設定ファイル(glob の書式。省略時は config の installer.php_fpm_pool_configs か DEFAULT_PATTERNS)
     * @return array{user: string, group: string}|null
     */
    public static function detect(?array $patterns = null): ?array
    {
        $patterns ??= config('installer.php_fpm_pool_configs', self::DEFAULT_PATTERNS);

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $content = @file_get_contents($file);
                if ($content === false) {
                    continue;
                }
                $user = self::value($content, 'user');
                if ($user !== null) {
                    return ['user' => $user, 'group' => self::value($content, 'group') ?? $user];
                }
            }
        }

        return null;
    }

    /**
     * 「user = apache」のような行の値(; で始まるコメント行は除く)
     */
    private static function value(string $content, string $key): ?string
    {
        if (preg_match('/^[ \t]*'.$key.'[ \t]*=[ \t]*([A-Za-z0-9._-]+)/m', $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
