<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\Trashable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * プロジェクト(お客様の案件単位)
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, Trashable;

    protected $fillable = [
        'key',
        'name',
        'description',
    ];

    /**
     * URLはプロジェクトキーで表す(例: /projects/WEB/issues)
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * 社内メンバー(新規課題の通知先)
     */
    public function staffMembers(): BelongsToMany
    {
        return $this->members()->where('role', UserRole::Staff->value);
    }

    public function customerMembers(): BelongsToMany
    {
        return $this->members()->where('role', UserRole::Customer->value);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
