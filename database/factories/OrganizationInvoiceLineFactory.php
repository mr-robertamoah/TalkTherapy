<?php

namespace Database\Factories;

use App\Models\Counsellor;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationInvoiceLine>
 */
class OrganizationInvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_invoice_id' => OrganizationInvoice::factory(),
            'session_id' => Session::factory(),
            'counsellor_id' => Counsellor::factory(['user_id' => User::factory()]),
            'net_amount' => $this->faker->numberBetween(1000, 10000),
            'fee_amount' => $this->faker->numberBetween(100, 1000),
            'currency' => 'GHS',
        ];
    }
}
