<?php

namespace App\Support;

use GdImage;
use Throwable;

/**
 * JPEGのEXIFに記録された「向き」(Orientation)の読み取りと補正。
 * スマホの写真は縦向きでも横長の画素+「90度回して表示」という情報で保存されていることが多く、
 * 縮小・サムネイル作成で再保存するとこの情報が消えて横倒しになるため、画素自体を回転させておく。
 * PHPのexif拡張が無いサーバーでも動くよう、EXIFは自前で解析する。
 */
final class JpegOrientation
{
    /**
     * 向き(1〜8、1が正常)を返す。読めない・壊れている場合は1(向きの補正をしないだけで、添付自体は失敗させない)
     */
    public static function read(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 1;
        }

        try {
            if (fread($handle, 2) !== "\xFF\xD8") {
                return 1; // JPEGではない
            }

            // 画像データ(SOS)より前のセグメントを順に見て、EXIF(APP1)を探す
            while (! feof($handle)) {
                $marker = fread($handle, 2);
                if (strlen($marker) < 2 || $marker[0] !== "\xFF") {
                    return 1;
                }
                $code = ord($marker[1]);
                if ($code === 0xD9 || $code === 0xDA) {
                    return 1;
                }

                $lengthBytes = fread($handle, 2);
                $length = strlen($lengthBytes) === 2 ? unpack('n', $lengthBytes)[1] : 0;
                if ($length < 2) {
                    return 1;
                }
                // 中身が空のセグメント(長さ2)もある。fread() に0を渡すとエラーになるため読まない
                $data = $length > 2 ? (string) fread($handle, $length - 2) : '';

                if ($code === 0xE1 && str_starts_with($data, "Exif\0\0")) {
                    return self::parseTiff(substr($data, 6));
                }
            }

            return 1;
        } catch (Throwable) {
            return 1;
        } finally {
            fclose($handle);
        }
    }

    /**
     * 向きに合わせて画像を回転・反転する
     */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        $rotate = fn (GdImage $img, int $angle) => imagerotate($img, $angle, 0) ?: $img;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return $rotate($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                // 右に90度回してから左右反転(=転置)
                $image = $rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 6:
                return $rotate($image, -90); // 右に90度
            case 7:
                $image = $rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 8:
                return $rotate($image, 90); // 左に90度
            default:
                return $image;
        }
    }

    /**
     * 縦横が入れ替わる向きか(5〜8)
     */
    public static function swapsDimensions(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    /**
     * EXIF(TIFF形式)の最初のIFDから Orientation(タグ 0x0112)を探す
     */
    private static function parseTiff(string $tiff): int
    {
        $byteOrder = substr($tiff, 0, 2);
        if ($byteOrder !== 'II' && $byteOrder !== 'MM') {
            return 1;
        }
        $littleEndian = $byteOrder === 'II';

        $u16 = fn (int $offset) => strlen($tiff) >= $offset + 2
            ? unpack($littleEndian ? 'v' : 'n', substr($tiff, $offset, 2))[1] : null;
        $u32 = fn (int $offset) => strlen($tiff) >= $offset + 4
            ? unpack($littleEndian ? 'V' : 'N', substr($tiff, $offset, 4))[1] : null;

        $ifdOffset = $u32(4);
        $entryCount = $ifdOffset === null ? null : $u16($ifdOffset);
        if ($entryCount === null) {
            return 1;
        }

        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $ifdOffset + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
