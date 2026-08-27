<?php

namespace Database\Factories;

use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\OrganizationCounsellorCompensation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationCounsellorCompensation>
 */
class OrganizationCounsellorCompensationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
            'effective_from' => now(),
        ];
    }
}
