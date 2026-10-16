<?php

namespace App\Http\Controllers;

use App\Support\ThemeColor;
use Illuminate\Http\Response;

/**
 * テーマカラーのCSS(/theme.css)。
 * CSP(style-src 'self')のもとでも使えるよう、色の設定は <style> に直接書かず、このCSSファイルとして読み込む。
 * URLに内容のハッシュ(?v=)を付けて読み込むので、長期間キャッシュさせる(色を変えるとURLが変わる)
 */
class ThemeController extends Controller
{
    public function __invoke(): Response
    {
        return response(ThemeColor::fromConfig()->stylesheet(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
