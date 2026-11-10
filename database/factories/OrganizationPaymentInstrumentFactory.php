<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationPaymentInstrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationPaymentInstrument>
 */
class OrganizationPaymentInstrumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'authorization_code' => 'AUTH_'.$this->faker->unique()->uuid(),
            'masked_card_number' => '**** '.$this->faker->numerify('####'),
            'card_type' => 'visa',
            'bank' => 'Test Bank',
            'exp_month' => '12',
            'exp_year' => '2030',
            'currency' => 'GHS',
            'pending_credit_amount' => null,
        ];
    }
}
