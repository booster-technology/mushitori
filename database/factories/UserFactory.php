<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Customer->value,
            'company_name' => fake()->company(),
            'is_active' => true,
        ];
    }

    /**
     * 社内メンバー
     */
    public function staff(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Staff->value,
            'company_name' => null,
        ]);
    }

    /**
     * お客様
     */
    public function customer(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Customer->value,
        ]);
    }

    /**
     * 無効化されたユーザー
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
