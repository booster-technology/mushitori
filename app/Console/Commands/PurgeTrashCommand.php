<?php

namespace App\Console\Commands;

use App\Services\TrashService;
use Illuminate\Console\Command;

/**
 * ゴミ箱で保管期間(.env の TRASH_RETENTION_DAYS)を過ぎたデータを完全に削除する。毎日自動で実行する(routes/console.php)
 */
class PurgeTrashCommand extends Command
{
    protected $signature = 'app:purge-trash';

    protected $description = 'ゴミ箱で保管期間を過ぎたデータを完全に削除する(画像などの添付ファイルの実体も削除)';

    public function handle(TrashService $trash): int
    {
        $days = $trash->retentionDays();
        if ($days === 0) {
            $this->info('TRASH_RETENTION_DAYS=0 のため、自動では削除しません。');

            return self::SUCCESS;
        }

        $counts = $trash->purgeExpired();
        $summary = collect(TrashService::TYPES)->map(fn (string $label, string $type) => "{$label} {$counts[$type]}件")->implode('・');
        $this->info("削除してから{$days}日を過ぎたデータを完全に削除しました（{$summary}）。");

        return self::SUCCESS;
    }
}
