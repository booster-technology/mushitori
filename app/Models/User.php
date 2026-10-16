<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * 社内メンバー・お客様の共通ユーザーモデル。
 * ロールごとに認証ガード(staff/customer)が分かれており、同じブラウザで両方に同時ログインできる。
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'role',
        'company_name',
        'is_active',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * 所属プロジェクト
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }

    /**
     * プロジェクトを閲覧できるか(社内は全プロジェクト、お客様は所属プロジェクトのみ)
     */
    public function canAccessProject(Project $project): bool
    {
        if ($this->isStaff()) {
            return true;
        }

        return $this->projects()->whereKey($project->id)->exists();
    }

    /**
     * 画面表示用の名前(お客様は会社名を併記)
     */
    public function displayName(): string
    {
        if ($this->isCustomer() && $this->company_name) {
            return "{$this->name}（{$this->company_name}）";
        }

        return $this->name;
    }

    /**
     * アバター代わりに表示する頭文字
     */
    public function initial(): string
    {
        return mb_substr($this->name, 0, 1);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeStaff(Builder $query): void
    {
        $query->where('role', UserRole::Staff->value);
    }

    /**
     * パスワード認証は使わない(メール+6桁コード方式)ため常に空
     */
    public function getAuthPassword(): string
    {
        return '';
    }
}
