<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * 論理削除(ゴミ箱)。削除日時(deleted_at)に加えて、削除した人(deleted_by)を記録する。
 * 削除したデータは保管期間のあいだゴミ箱(App\Services\TrashService)から元に戻せ、期間が過ぎると完全に削除される
 */
trait Trashable
{
    use SoftDeletes;

    /**
     * 削除した人
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * 削除した人を記録して論理削除する(更新日時は変えない)
     */
    public function trashBy(?User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->update(['deleted_by' => $user?->id]);
            $this->setAttribute('deleted_by', $user?->id);
            $this->syncOriginalAttribute('deleted_by');
            $this->delete();
        });
    }

    /**
     * ゴミ箱から元に戻す(削除した人の記録も消す)
     */
    public function restoreFromTrash(): void
    {
        $this->setAttribute('deleted_by', null);
        $this->restore();
    }
}
