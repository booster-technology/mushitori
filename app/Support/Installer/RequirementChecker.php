<?php

namespace App\Support\Installer;

/**
 * インストール前の動作環境チェック(PHPのバージョン・拡張モジュール・書き込み権限)
 */
class RequirementChecker
{
    public const MIN_PHP_VERSION = '8.3.0';

    /** 必須のPHP拡張モジュールと、入っていない場合の説明 */
    private const REQUIRED_EXTENSIONS = [
        'mbstring' => '日本語の文字列処理',
        'openssl' => '暗号化',
        'pdo' => 'データベース接続',
        'fileinfo' => '添付ファイルの形式判定',
        'gd' => '画像のサムネイル作成',
        'ctype' => 'Laravel本体',
        'tokenizer' => 'Laravel本体',
        'xml' => 'Laravel本体',
    ];

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * すべてのチェック結果
     *
     * @return list<array{label: string, ok: bool, hint: string}>
     */
    public function check(): array
    {
        $results = [[
            'label' => 'PHP '.PHP_VERSION.'（'.self::MIN_PHP_VERSION.' 以上が必要）',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'hint' => 'PHP 8.3 以上をインストールしてください。',
        ]];

        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            $results[] = [
                'label' => "PHP拡張 {$extension}（{$purpose}）",
                'ok' => extension_loaded($extension),
                'hint' => "php-{$extension} をインストールして有効にしてください。",
            ];
        }

        $results[] = [
            'label' => 'データベース用のPHP拡張（pdo_mysql または pdo_sqlite）',
            'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'hint' => 'MySQLを使う場合は php-mysql(pdo_mysql)、SQLiteを使う場合は php-sqlite3(pdo_sqlite) をインストールしてください。',
        ];

        foreach (['storage', 'bootstrap/cache'] as $dir) {
            $path = $this->basePath.DIRECTORY_SEPARATOR.$dir;
            $results[] = [
                'label' => "{$dir}/ に書き込める",
                'ok' => is_dir($path) && is_writable($path),
                'hint' => "Webサーバーの実行ユーザーが {$dir}/ に書き込めるようにしてください（例: chown -R www-data {$dir}）。",
            ];
        }

        $results[] = [
            'label' => '.env を作成・更新できる',
            'ok' => is_writable($this->basePath.DIRECTORY_SEPARATOR.'.env') || (! file_exists($this->basePath.DIRECTORY_SEPARATOR.'.env') && is_writable($this->basePath)),
            'hint' => 'インストール先のディレクトリ（または .env）に書き込めるようにしてください。',
        ];

        return $results;
    }

    /**
     * 使えるデータベースの種類
     *
     * @return list<string>
     */
    public function availableDatabases(): array
    {
        return array_values(array_filter([
            extension_loaded('pdo_mysql') ? 'mysql' : null,
            extension_loaded('pdo_sqlite') ? 'sqlite' : null,
        ]));
    }
}
