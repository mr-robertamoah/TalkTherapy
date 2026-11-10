<?php

namespace Database\Factories;

use App\Enums\OrganizationMemberBillingModeEnum;
use App\Models\OrganizationMemberBillingConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMemberBillingConfig>
 */
class OrganizationMemberBillingConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'include_group_therapies' => true,
            'effective_from' => now(),
        ];
    }
}
