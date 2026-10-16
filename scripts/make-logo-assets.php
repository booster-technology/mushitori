<?php

/**
 * ロゴの元画像(resources/brand/logo-original.png、透過PNG)から、Web用の画像一式を作り直す。
 *   php -d memory_limit=1G scripts/make-logo-assets.php
 *
 * 作るもの: public/images/logo.png(明るい背景用) / logo-white.png(濃い色のヘッダー用・文字だけ白)
 *           public/images/icon-512.png・icon-192.png(網と虫のアイコン) / public/apple-touch-icon.png / public/favicon.ico
 *
 * ※「網の柄より右側(元画像で x=700 以降)の暗い画素 = 文字」「x=840 までがアイコン」という前提なので、
 *   ロゴの配置を大きく変えた場合は下の 700・840 を調整すること
 */
$base = dirname(__DIR__);
$srcPath = "$base/resources/brand/logo-original.png";
$src = imagecreatefrompng($srcPath);
$W = imagesx($src);
$H = imagesy($src);

function blank(int $w, int $h): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));

    return $im;
}
function resizeTo(GdImage $im, int $h): GdImage
{
    $w = (int) round(imagesx($im) * $h / imagesy($im));
    $out = blank($w, $h);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));

    return $out;
}
function savePng(GdImage $im, string $path): void
{
    imagepng($im, $path, 9);
    printf("  %-40s %4dx%-4d %6.1fKB\n", basename(dirname($path)).'/'.basename($path), imagesx($im), imagesy($im), filesize($path) / 1024);
}

// 1. 透明な余白を詰める(内容の範囲 + 少しの余白)
$minX = $W;
$minY = $H;
$maxX = 0;
$maxY = 0;
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        if (((imagecolorat($src, $x, $y) >> 24) & 0x7F) < 100) {
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }
}
$pad = 12;
$cx = max(0, $minX - $pad);
$cy = max(0, $minY - $pad);
$cw = min($W, $maxX + $pad + 1) - $cx;
$ch = min($H, $maxY + $pad + 1) - $cy;
$trim = blank($cw, $ch);
imagecopy($trim, $src, 0, 0, $cx, $cy, $cw, $ch);

// 文字(濃い青緑)の画素か: 網の柄より右側にある、暗くて青みのある画素
$textStartX = 700 - $cx;
$isText = function (int $x, int $c) use ($textStartX): bool {
    if ($x < $textStartX) {
        return false;
    }
    $r = ($c >> 16) & 0xFF;
    $g = ($c >> 8) & 0xFF;
    $b = $c & 0xFF;
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

    return $lum < 0.55 && $b >= $r;
};

// 2. 白文字版(濃い色のヘッダー用)
$white = blank($cw, $ch);
imagecopy($white, $trim, 0, 0, 0, 0, $cw, $ch);
$changed = 0;
for ($y = 0; $y < $ch; $y++) {
    for ($x = 0; $x < $cw; $x++) {
        $c = imagecolorat($white, $x, $y);
        $a = ($c >> 24) & 0x7F;
        if ($a < 127 && $isText($x, $c)) {
            imagesetpixel($white, $x, $y, imagecolorallocatealpha($white, 255, 255, 255, $a));
            $changed++;
        }
    }
}
echo "白文字にした画素: $changed\n";

// 3. アイコン(網と虫だけを正方形に)。文字が少しでも入らないよう、文字の画素は透明にする
$iconRight = 840 - $cx;
$ic = blank($iconRight, $ch);
imagecopy($ic, $trim, 0, 0, 0, 0, $iconRight, $ch);
for ($y = 0; $y < $ch; $y++) {
    for ($x = 0; $x < $iconRight; $x++) {
        $c = imagecolorat($ic, $x, $y);
        if ((($c >> 24) & 0x7F) < 127 && $isText($x, $c)) {
            imagesetpixel($ic, $x, $y, imagecolorallocatealpha($ic, 0, 0, 0, 127));
        }
    }
}
$side = max($iconRight, $ch);
$square = blank($side, $side);
imagecopy($square, $ic, intdiv($side - $iconRight, 2), intdiv($side - $ch, 2), 0, 0, $iconRight, $ch);

// 4. 書き出し
echo "書き出し:\n";
savePng(resizeTo($trim, 192), "$base/public/images/logo.png");        // ログイン画面などの明るい背景用
savePng(resizeTo($white, 96), "$base/public/images/logo-white.png");  // 濃い色のヘッダー用
foreach ([512, 192] as $s) {
    savePng(resizeTo($square, $s), "$base/public/images/icon-$s.png");
}
// iPhoneのホーム画面用は透過が使えないので白背景
$apple = imagecreatetruecolor(180, 180);
imagefill($apple, 0, 0, imagecolorallocate($apple, 255, 255, 255));
imagecopyresampled($apple, $square, 14, 14, 0, 0, 152, 152, $side, $side);
savePng($apple, "$base/public/apple-touch-icon.png");
// favicon.ico(16・32・48px のPNGを格納。今のブラウザはすべて対応)
$entries = [];
foreach ([16, 32, 48] as $s) {
    ob_start();
    imagepng(resizeTo($square, $s), null, 9);
    $entries[$s] = ob_get_clean();
}
$ico = pack('vvv', 0, 1, count($entries));
$offset = 6 + 16 * count($entries);
$data = '';
foreach ($entries as $s => $png) {
    $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($png), $offset);
    $offset += strlen($png);
    $data .= $png;
}
file_put_contents("$base/public/favicon.ico", $ico.$data);
printf("  %-40s %s %6.1fKB\n", 'public/favicon.ico', '16/32/48', filesize("$base/public/favicon.ico") / 1024);
