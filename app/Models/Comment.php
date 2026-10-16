<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 課題へのコメント。状態・担当者などの変更履歴(change_log)も一緒に記録する。
 * 削除しても変更履歴は残すため論理削除にしている。
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory, Trashable;

    protected $fillable = [
        'issue_id',
        'user_id',
        'body',
        'change_log',
    ];

    protected function casts(): array
    {
        return [
            'change_log' => 'array',
            'purged_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
