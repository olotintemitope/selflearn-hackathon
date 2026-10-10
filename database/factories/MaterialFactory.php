<?php

namespace Database\Factories;

use App\Models\LearningSpace;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learning_space_id' => LearningSpace::factory(),
            'title' => fake()->sentence(3),
            'private_source_path' => 'materials/test/notes.png',
            'original_filename' => 'notes.png',
            'mime_type' => 'image/png',
            'size_bytes' => 128,
            'status' => 'pending',
            'extraction_version' => 0,
        ];
    }
}
