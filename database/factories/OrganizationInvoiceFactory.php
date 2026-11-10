<?php

namespace Database\Factories;

use App\Enums\OrganizationInvoiceStatusEnum;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationInvoice>
 */
class OrganizationInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'currency' => 'GHS',
            // A closed, already-settlement-eligible period by default -- last month, not the
            // current one -- since most callers of this factory are testing settlement itself
            // (SettleOrganizationInvoiceAction refuses to claim an invoice whose period hasn't
            // closed yet). Override explicitly for a test that specifically needs a still-open,
            // current-period invoice.
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'status' => OrganizationInvoiceStatusEnum::open->value,
            'amount' => null,
        ];
    }
}
