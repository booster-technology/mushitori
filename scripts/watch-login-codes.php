<?php

/**
 * お試し・開発用: メールを送信しない設定(MAIL_MAILER=log)のとき、
 * ログファイルに出力されたログイン認証コードをリアルタイムに表示する。
 *
 *   php scripts/watch-login-codes.php      (Ctrl+C で終了)
 */
date_default_timezone_set('Asia/Tokyo');
$logDir = dirname(__DIR__).'/storage/logs';

echo "ログイン認証コードの出力を待っています…（Ctrl+C で終了）\n";
echo "ログイン画面でメールアドレスを入力すると、ここに6桁のコードが表示されます。\n\n";

/**
 * 最新のログファイル(LOG_CHANNEL=daily なら laravel-日付.log、single なら laravel.log)
 */
$latestLog = function () use ($logDir): ?string {
    $files = glob($logDir.'/laravel*.log') ?: [];
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

    return $files[0] ?? null;
};

$current = $latestLog();
$position = $current ? filesize($current) : 0;
$recipient = '';

while (true) {
    clearstatcache();
    $file = $latestLog();
    if ($file !== $current) {
        // 日付が変わってログファイルが切り替わった
        $current = $file;
        $position = 0;
    }

    if ($current !== null && filesize($current) > $position) {
        $handle = fopen($current, 'rb');
        fseek($handle, $position);
        while (($line = fgets($handle)) !== false) {
            // 宛先(メールのヘッダー)
            if (preg_match('/^To: .*?([^\s<>"]+@[^\s<>"]+)/', $line, $m)) {
                $recipient = $m[1];
            }
            // 認証コード(メール本文の大きく表示している部分)
            if (preg_match('/letter-spacing: 8px; text-align: center;">(\d{6})</', $line, $m)) {
                echo date('H:i:s')."  認証コード: \033[1;32m{$m[1]}\033[0m   宛先: {$recipient}\n";
            }
        }
        $position = ftell($handle);
        fclose($handle);
    } elseif ($current !== null && filesize($current) < $position) {
        $position = 0; // ログが削除・作り直された
    }

    usleep(500_000);
}
