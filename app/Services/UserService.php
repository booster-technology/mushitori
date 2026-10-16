<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ユーザー(社内メンバー・お客様)の管理(社内メンバーのみ操作可)
 */
class UserService
{
    /** ユーザー一覧の1ページあたりの表示件数 */
    public const PER_PAGE = 20;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * ユーザー一覧の検索(種別・キーワード)。有効なユーザー → 社内 → 会社名 → 氏名 の順
     */
    public function search(?UserRole $role, string $keyword): LengthAwarePaginator
    {
        return User::query()
            ->withCount('projects')
            ->when($role, fn (Builder $q) => $q->where('role', $role->value))
            ->when($keyword !== '', function (Builder $q) use ($keyword) {
                $like = like_contains($keyword);
                $q->where(fn (Builder $w) => $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("company_name LIKE ? ESCAPE '!'", [$like]));
            })
            ->orderByDesc('is_active')
            ->orderBy('role', 'desc')
            ->orderBy('company_name')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * 課題の担当者として選べる社内メンバー(有効な人のみ)
     *
     * @return Collection<int, User>
     */
    public function assignableStaff(): Collection
    {
        return User::staff()->active()->orderBy('name')->get();
    }

    /**
     * プロジェクトの課題に担当者として設定されている社内メンバー(お客様用の一覧画面の「担当者」での絞り込み用。
     * お客様には、関係のない社内メンバーの名前まで見せない)
     *
     * @return Collection<int, User>
     */
    public function assigneesOf(Project $project): Collection
    {
        return User::staff()
            ->whereIn('id', $project->issues()->whereNotNull('assignee_id')->select('assignee_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * プロジェクトのメンバーに追加できるユーザー(有効で、まだメンバーでない人。社内 → お客様の順)
     *
     * @param  list<int>  $excludeIds
     * @return Collection<int, User>
     */
    public function memberCandidates(array $excludeIds = []): Collection
    {
        return User::active()
            ->whereNotIn('id', $excludeIds)
            ->orderBy('role', 'desc')
            ->orderBy('company_name')
            ->orderBy('name')
            ->get();
    }

    /**
     * 編集画面用に、参加プロジェクトを読み込む
     */
    public function loadForEdit(User $user): User
    {
        return $user->load('projects');
    }

    /**
     * ユーザーを作成し、必要ならログイン案内メールを送る
     *
     * @param  array<string, mixed>  $data
     * @param  list<int|string>  $projectIds
     */
    public function create(array $data, array $projectIds = [], bool $sendWelcomeMail = true): User
    {
        $user = DB::transaction(function () use ($data, $projectIds) {
            $user = User::create($this->attributes($data) + ['is_active' => true]);
            $user->projects()->sync($projectIds);

            return $user;
        });

        if ($sendWelcomeMail) {
            $this->notifications->accountCreated($user);
        }

        return $user;
    }

    /**
     * ユーザー情報と参加プロジェクトを更新する。自分自身を無効化・お客様に変更して締め出されることは防ぐ
     *
     * @param  array<string, mixed>  $data
     * @param  list<int|string>  $projectIds
     */
    public function update(User $user, array $data, User $actor, array $projectIds = []): User
    {
        $attributes = $this->attributes($data) + ['is_active' => (bool) ($data['is_active'] ?? false)];

        if ($user->is($actor) && (! $attributes['is_active'] || $attributes['role'] !== UserRole::Staff->value)) {
            throw ValidationException::withMessages([
                'is_active' => '自分自身を無効化したり、お客様に変更したりすることはできません。',
            ]);
        }

        DB::transaction(function () use ($user, $attributes, $projectIds) {
            // 無効化した・ロールを変えた場合は「ログインしたままにする」を取り消す
            // (後で有効に戻したときに、以前のブラウザの自動ログインが復活しないように)
            if (! $attributes['is_active'] || $attributes['role'] !== $user->role->value) {
                $user->forceFill(['remember_token' => null]);
            }
            $user->update($attributes);
            $user->projects()->sync($projectIds);
        });

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $role = UserRole::from($data['role']);

        return [
            'name' => $data['name'],
            // メールアドレスは小文字で統一して保存する(ログイン時の照合を確実にするため)
            'email' => LoginCodeService::normalizeEmail($data['email']),
            'role' => $role->value,
            'company_name' => $role === UserRole::Customer ? ($data['company_name'] ?? null) : null,
        ];
    }
}
