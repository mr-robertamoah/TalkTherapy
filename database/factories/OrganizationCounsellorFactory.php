<?php

namespace Database\Factories;

use App\Enums\OrganizationCounsellorSourceEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Models\OrganizationCounsellor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationCounsellor>
 */
class OrganizationCounsellorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => OrganizationCounsellorStatusEnum::pending->value,
            'source' => OrganizationCounsellorSourceEnum::invited->value,
        ];
    }
}
