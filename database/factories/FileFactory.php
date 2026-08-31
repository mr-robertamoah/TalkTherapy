<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->uuid.'.jpg',
            'mime' => 'image/jpeg',
            'size' => $this->faker->numberBetween(1000, 500000),
            'path' => 'files',
            'storage' => 'public',
        ];
    }
}
