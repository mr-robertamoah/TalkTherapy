<?php

namespace Database\Factories;

use App\Enums\AdministratorTypeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Administrator>
 */
class AdministratorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'verified_at' => now(),
            'type' => AdministratorTypeEnum::super->value
        ];
    }
}
