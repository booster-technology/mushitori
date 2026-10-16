<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 本番・開発共通の初期データ。最初の社内メンバーを1人作成する(.env の INITIAL_STAFF_EMAIL / INITIAL_STAFF_NAME)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => mb_strtolower((string) config('app.initial_staff_email'))],
            [
                'name' => (string) config('app.initial_staff_name'),
                'role' => UserRole::Staff->value,
                'is_active' => true,
            ]
        );
    }
}
