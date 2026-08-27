<?php

namespace Database\Factories;

use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMember>
 */
class OrganizationMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => OrganizationMemberStatusEnum::active->value,
            'source' => OrganizationMemberSourceEnum::invited->value,
        ];
    }
}
