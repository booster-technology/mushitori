<?php

use App\Models\LoginCode;
use Illuminate\Support\Facades\Schedule;

/*
| 定期実行(サーバーのcronに「* * * * * cd /var/www/mushitori && php artisan schedule:run >> /dev/null 2>&1」を登録する)
*/

// 使い終わった・期限切れのログイン認証コードを毎日削除する
Schedule::command('model:prune', ['--model' => [LoginCode::class]])->daily();

// ゴミ箱で保管期間(.env の TRASH_RETENTION_DAYS。既定30日)を過ぎた削除済みデータを、毎日完全に削除する
Schedule::command('app:purge-trash')->dailyAt('04:00')->withoutOverlapping();

// 毎日深夜3時にバックアップする(.env で BACKUP_DAILY=true にした場合のみ)
if (config('backup.daily')) {
    Schedule::command('app:backup')->dailyAt('03:00')->withoutOverlapping();
}
