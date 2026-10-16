<?php

namespace App\Models;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Models\Concerns\Trashable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 課題(不具合・要望・質問など)
 */
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, Trashable;

    protected $fillable = [
        'project_id',
        'number',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'reporter_id',
        'assignee_id',
        'due_date',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => IssueType::class,
            'priority' => IssuePriority::class,
            'status' => IssueStatus::class,
            'due_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * 課題本文に添付されたファイル(コメントの添付は含まない)
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->whereNull('comment_id');
    }

    /**
     * コメント分も含めた全添付ファイル
     */
    public function allAttachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * 課題キー(例: WEB-12)
     */
    public function issueKey(): string
    {
        return $this->project->key.'-'.$this->number;
    }

    /**
     * URLは課題キーで表す(例: /issues/WEB-12)
     */
    public function getRouteKey(): string
    {
        return $this->issueKey();
    }

    /**
     * 課題キー(WEB-12)からモデルを解決する。プロジェクトキーに「-」は使えないため最後の「-」で分割する
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (! is_string($value) || ! preg_match('/^([A-Z][A-Z0-9_]*)-(\d+)$/', $value, $m)) {
            return null;
        }

        return $this->newQuery()
            ->whereHas('project', fn ($q) => $q->where('key', $m[1]))
            ->where('number', (int) $m[2])
            ->first();
    }

    /**
     * 期限切れ(完了以外で期限日を過ぎている)か
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status !== IssueStatus::Closed
            && $this->due_date->lt(today());
    }
}
