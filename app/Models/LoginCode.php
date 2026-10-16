<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ログイン用の6桁認証コード(ハッシュ値のみ保存)
 */
class LoginCode extends Model
{
    use MassPrunable;

    protected $fillable = [
        'user_id',
        'guard',
        'code_hash',
        'expires_at',
        'attempts',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * 1日以上前に発行したコードは不要なので定期的に削除する(php artisan model:prune)
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
