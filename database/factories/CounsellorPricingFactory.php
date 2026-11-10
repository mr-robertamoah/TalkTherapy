<?php

namespace Database\Factories;

use App\Models\CounsellorPricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CounsellorPricing>
 */
class CounsellorPricingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => 150,
            'currency' => 'GHS',
        ];
    }
}
