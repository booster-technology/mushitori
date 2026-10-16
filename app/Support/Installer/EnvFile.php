<?php

namespace App\Support\Installer;

use RuntimeException;

/**
 * .env ファイルの読み書き(インストーラ用)。
 * コメントや並び順はそのまま残し、指定したキーの行だけを書き換える。
 */
class EnvFile
{
    /** @var list<string> */
    private array $lines;

    public function __construct(private readonly string $path)
    {
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $this->lines = $content === '' ? [] : preg_split('/\r\n|\n|\r/', rtrim($content, "\r\n"));
    }

    /**
     * .env が無ければ .env.example をコピーして作る
     */
    public static function createFromExample(string $path, string $examplePath): self
    {
        if (! is_file($path)) {
            if (! is_file($examplePath) || ! copy($examplePath, $path)) {
                throw new RuntimeException('.env ファイルを作成できませんでした。ディレクトリの書き込み権限を確認してください。');
            }
        }

        return new self($path);
    }

    /**
     * 値を取得する(未設定なら null)。引用符は外して返す
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $m)) {
                return $this->unquote(trim($m[1]));
            }
        }

        return null;
    }

    /**
     * 値を設定する。既存の行(コメントアウトされた行を含む)があれば置き換え、無ければ末尾に追加する
     */
    public function set(string $key, string|int|bool|null $value): self
    {
        $line = $key.'='.self::format($value);
        $pattern = '/^\s*#?\s*'.preg_quote($key, '/').'\s*=/';

        foreach ($this->lines as $i => $existing) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                $this->lines[$i] = $line;

                return $this;
            }
        }
        // 有効な行が無ければ、コメントアウトされた同じキーの行を置き換える
        foreach ($this->lines as $i => $existing) {
            if (preg_match($pattern, $existing)) {
                $this->lines[$i] = $line;

                return $this;
            }
        }

        $this->lines[] = $line;

        return $this;
    }

    /**
     * キーの行をコメントアウトする(SQLite利用時の DB_DATABASE など、既定値に任せたい設定用)
     */
    public function remove(string $key): self
    {
        foreach ($this->lines as $i => $existing) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                $this->lines[$i] = '# '.$existing;
            }
        }

        return $this;
    }

    public function save(): void
    {
        if (file_put_contents($this->path, implode("\n", $this->lines)."\n") === false) {
            throw new RuntimeException('.env ファイルに書き込めませんでした。ファイルの書き込み権限を確認してください。');
        }
    }

    /**
     * 値を .env の書式にする。記号を含む値は引用符で囲み、パスワード中の「$」などが変数展開されないようにする
     */
    public static function format(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        // 英数字といくつかの記号だけなら引用符不要
        if (preg_match('/^[A-Za-z0-9_.\-:\/@+]+$/', $value)) {
            return $value;
        }
        // シングルクォートの中は一切展開されないので、' を含まなければこれが最も安全
        if (! str_contains($value, "'") && ! preg_match('/[\r\n]/', $value)) {
            return "'".$value."'";
        }

        // ' を含む場合はダブルクォート(\ " $ をエスケープ)
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], substr($value, 1, -1));
        }

        return $value;
    }
}
