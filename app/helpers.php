<?php

use App\Support\Portal;

if (! function_exists('portal')) {
    /**
     * 現在のポータル名(staff / customer)
     */
    function portal(): string
    {
        return Portal::current();
    }
}

if (! function_exists('portal_route')) {
    /**
     * 現在のポータルのルートURL(ビューで route() の代わりに使う)
     */
    function portal_route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return Portal::route($name, $parameters, $absolute);
    }
}

if (! function_exists('asset_v')) {
    /**
     * キャッシュ対策として更新日時のクエリを付けたアセットURL
     */
    function asset_v(string $path): string
    {
        $full = public_path($path);

        return asset($path).(is_file($full) ? '?v='.filemtime($full) : '');
    }
}

if (! function_exists('like_contains')) {
    /**
     * 部分一致検索用のLIKEパターン。「%」「_」を文字として検索できるよう「!」でエスケープする
     * (MySQLとSQLiteの両方で同じ動きにするため、使う側で ESCAPE '!' を指定すること)
     */
    function like_contains(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}

if (! function_exists('mail_escape')) {
    /**
     * メール(Markdown形式)に利用者の入力を埋め込む際、Markdownの記号をエスケープする。
     * 件名や本文に「[文字](URL)」を書いて偽のリンクを作られるのを防ぐ。HTMLのエスケープは別途 {{ }} / e() で行う
     */
    function mail_escape(?string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/', '\\\\$1', (string) $text) ?? '';
    }
}

if (! function_exists('format_text')) {
    /**
     * 課題・コメント本文の表示用整形。HTMLエスケープした上で改行を<br>に、URLをリンクに変換する
     */
    function format_text(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $escaped = e($text);
        $linked = preg_replace(
            // URLに使える半角文字だけを対象にする(直後の日本語や全角の句読点をリンクに含めない)
            '~https?://[A-Za-z0-9\-._\~:/?#\[\]@!$&()*+,;=%]+~',
            '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>',
            $escaped
        );

        return nl2br($linked ?? $escaped, false);
    }
}

if (! function_exists('is_same_site_url')) {
    /**
     * URLがこのサイト(スキーム・ホスト・ポートが同じ)のものか。
     * 先頭の文字列だけで比べると「https://issues.example.com.evil.example/」のような偽のサイトも通ってしまうため、部品ごとに比べる
     */
    function is_same_site_url(string $url): bool
    {
        $target = parse_url($url);
        $site = parse_url(url('/'));
        if (! is_array($target) || ! is_array($site) || ! isset($target['scheme'], $target['host'])) {
            return false;
        }

        return strtolower($target['scheme']) === strtolower($site['scheme'] ?? '')
            && strtolower($target['host']) === strtolower($site['host'] ?? '')
            && ($target['port'] ?? null) === ($site['port'] ?? null);
    }
}
