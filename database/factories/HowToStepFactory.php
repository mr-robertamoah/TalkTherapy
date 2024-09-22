<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HowToStep>
 */
class HowToStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'how_to_id' => 1,
            'position' => 1,
            'description' => $this->faker->sentence,
            'element_id' => 'example-id',
        ];
    }
}
