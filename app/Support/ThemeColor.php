<?php

namespace App\Support;

/**
 * 画面のテーマカラー(.env の APP_THEME_COLOR)。
 * 指定された1色から、ホバー色・リンク色・その上に載せる文字色(白/黒)を自動で計算し、CSS変数として出力する。
 * 明るい色を選んでも文字が読めるよう、文字色はコントラスト比が高い方を選ぶ。
 */
final class ThemeColor
{
    public const DEFAULT = '#0D223A';

    /** インストーラで選べる色(キーは --theme-color でも指定できる) */
    public const PRESETS = [
        'navy' => ['#0D223A', '濃紺（標準）'],
        'green' => ['#1F8A70', '緑'],
        'blue' => ['#1E5AA8', '青'],
        'red' => ['#B8332E', '赤'],
        'purple' => ['#6B3FA0', '紫'],
        'gray' => ['#3A3F47', 'グレー'],
    ];

    /** 本文の文字色(Bootstrapの標準) */
    private const TEXT_DARK = '#212529';

    /** @var array{0: int, 1: int, 2: int} */
    private array $rgb;

    public function __construct(string $hex)
    {
        $normalized = self::normalize($hex) ?? self::DEFAULT;
        $this->rgb = [
            (int) hexdec(substr($normalized, 1, 2)),
            (int) hexdec(substr($normalized, 3, 2)),
            (int) hexdec(substr($normalized, 5, 2)),
        ];
    }

    /**
     * 設定値から作る(不正な値なら標準色)
     */
    public static function fromConfig(): self
    {
        return new self((string) config('app.theme_color', self::DEFAULT));
    }

    /**
     * 「#0d223a」「0D223A」「#abc」などを「#0D223A」形式にする。色として不正なら null
     */
    public static function normalize(?string $value): ?string
    {
        $value = ltrim(trim((string) $value), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9a-fA-F]{6}$/', $value) ? '#'.strtoupper($value) : null;
    }

    /**
     * プリセット名(navy など)またはカラーコードから色を決める。不正なら null
     */
    public static function resolve(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));

        return isset(self::PRESETS[$key]) ? self::PRESETS[$key][0] : self::normalize($value);
    }

    public function hex(): string
    {
        return self::toHex($this->rgb);
    }

    /**
     * 暗い色か(=上に載せる文字を白にするか)
     */
    public function isDark(): bool
    {
        return $this->onColor() === '#FFFFFF';
    }

    /**
     * この色の上に載せる文字色。白と黒のうちコントラスト比が高い方
     */
    public function onColor(): string
    {
        $white = self::contrast($this->rgb, [255, 255, 255]);
        $dark = self::contrast($this->rgb, self::fromHex(self::TEXT_DARK));

        return $white >= $dark ? '#FFFFFF' : self::TEXT_DARK;
    }

    /**
     * ホバー時の色(暗い色は少し明るく、明るい色は少し暗く)
     */
    public function hover(): string
    {
        [$h, $s, $l] = self::toHsl($this->rgb);

        return self::toHex(self::fromHsl($h, $s, $this->isDark() ? min($l + 0.08, 0.9) : max($l - 0.08, 0)));
    }

    /**
     * 押した瞬間の色
     */
    public function active(): string
    {
        [$h, $s, $l] = self::toHsl($this->rgb);

        return self::toHex(self::fromHsl($h, $s, max($l - ($this->isDark() ? 0.04 : 0.12), 0)));
    }

    /**
     * リンク・フォーカス枠用の色。
     * 白背景で読める濃さ(コントラスト比4.5以上)にしつつ、黒い本文と見分けがつく明るさにする
     */
    public function link(): string
    {
        [$h, $s, $l] = self::toHsl($this->rgb);
        $l = min(max($l, 0.32), 0.42);

        $rgb = self::fromHsl($h, $s, $l);
        while (self::contrast($rgb, [255, 255, 255]) < 4.5 && $l > 0.05) {
            $l -= 0.02;
            $rgb = self::fromHsl($h, $s, $l);
        }

        return self::toHex($rgb);
    }

    /**
     * テーマのCSS(/theme.css の中身。テーマカラーのCSS変数)。
     * CSP(style-src 'self')のもとでも使えるよう、<style> や style 属性ではなくCSSファイルとして読み込む
     */
    public function stylesheet(): string
    {
        return $this->cssVariables()."\n";
    }

    /**
     * /theme.css の版(内容が変わると変わる)。URLに付けてブラウザのキャッシュを切り替える
     */
    public function version(): string
    {
        return substr(sha1($this->stylesheet()), 0, 10);
    }

    /**
     * テーマのCSS変数(値はすべて計算済みの #RRGGBB / 数値なので安全)
     */
    public function cssVariables(): string
    {
        $link = self::fromHex($this->link());

        return ':root{'
            .'--mt-primary:'.$this->hex().';'
            .'--mt-primary-rgb:'.implode(', ', $this->rgb).';'
            .'--mt-primary-hover:'.$this->hover().';'
            .'--mt-primary-active:'.$this->active().';'
            .'--mt-on-primary:'.$this->onColor().';'
            .'--mt-link:'.$this->link().';'
            .'--mt-link-rgb:'.implode(', ', $link).';'
            .'}';
    }

    /**
     * 2色のコントラスト比(WCAGの計算式)
     *
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    public static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function fromHex(string $hex): array
    {
        return (new self($hex))->rgb;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private static function luminance(array $rgb): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', ...$rgb);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: float, 1: float, 2: float} 色相(0-360)・彩度(0-1)・明度(0-1)
     */
    private static function toHsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(fn (int $v) => $v / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function fromHsl(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }
}
