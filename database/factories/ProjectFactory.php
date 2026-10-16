<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'P'.strtoupper(fake()->unique()->bothify('??##')),
            'name' => fake()->company().' 保守',
            'description' => null,
        ];
    }
}
