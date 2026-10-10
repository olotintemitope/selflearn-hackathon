<?php

namespace Database\Factories;

use App\Models\LearningSpace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearningSpace>
 */
class LearningSpaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'goal' => fake()->sentence(),
            'preferred_language' => 'en',
        ];
    }
}
