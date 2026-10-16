<?php

/*
| バックアップ(php artisan app:backup)の設定
*/
return [
    // 毎日深夜3時に自動でバックアップするか(サーバーのcronで schedule:run が動いている必要がある)
    'daily' => (bool) env('BACKUP_DAILY', false),

    // 残しておく世代数(古いものから削除。0なら削除しない)
    'keep' => (int) env('BACKUP_KEEP', 7),

    // 保存先(サーバーが壊れた時に備えて、別ディスクやS3等へのコピーも推奨)
    'path' => env('BACKUP_PATH', storage_path('backups')),

    // MySQLのダンプに使う mysqldump コマンドの場所(PATHが通っていなければフルパスで指定)
    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
];
