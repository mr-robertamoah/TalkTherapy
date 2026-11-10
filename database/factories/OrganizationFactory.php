<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'legal_name' => $this->faker->company.' Ltd',
            'registration_number' => $this->faker->unique()->bothify('REG-########'),
            'description' => $this->faker->sentence,
            'email' => $this->faker->unique()->companyEmail,
            'phone' => $this->faker->phoneNumber,
            'is_provider' => true,
            'is_consumer' => false,
            'verified_at' => null,
        ];
    }
}
