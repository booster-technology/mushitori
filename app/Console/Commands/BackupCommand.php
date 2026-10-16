<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * データベース・添付ファイル・設定を1つのファイルにバックアップする
 */
class BackupCommand extends Command
{
    protected $signature = 'app:backup
        {--path= : 保存先のディレクトリ(省略時は .env の BACKUP_PATH、既定は storage/backups)}
        {--keep= : 残す世代数(省略時は .env の BACKUP_KEEP、既定は7。0なら削除しない)}
        {--without-env : .env(パスワード等)をバックアップに含めない}';

    protected $description = 'データベース・添付ファイル・設定をバックアップする';

    public function handle(BackupService $backups): int
    {
        $directory = (string) ($this->option('path') ?: config('backup.path'));
        $keep = (int) ($this->option('keep') ?? config('backup.keep'));

        try {
            $this->info('バックアップを作成しています…');
            $file = $backups->create($directory, ! $this->option('without-env'));
            $this->line('  作成しました: '.$file.'（'.$this->humanSize((int) filesize($file)).'）');

            foreach ($backups->prune($directory, $keep) as $deleted) {
                $this->line('  古いバックアップを削除しました: '.basename($deleted));
            }
        } catch (Throwable $e) {
            $this->error('バックアップに失敗しました: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->comment('※ バックアップにはお客様のデータとパスワード等が含まれます。サーバーの故障に備えて、別の安全な場所にもコピーしてください。');

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $bytes : number_format($bytes, 1)).' '.$unit;
            }
            $bytes /= 1024;
        }

        return '';
    }
}
