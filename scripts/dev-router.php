<?php

/**
 * ローカル開発用サーバーのルーター。
 * php artisan serve では php.ini のアップロード上限(既定2MB)を変えられないため、
 * スクリーンショットの添付を試せるよう上限を上げて php -S で起動するときに使う。
 *
 *   php -d upload_max_filesize=20M -d post_max_size=120M -S 127.0.0.1:8000 -t public scripts/dev-router.php
 */
$publicPath = dirname(__DIR__).'/public';
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// 静的ファイル(css/js/画像)は組み込みサーバーにそのまま返させる
if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
