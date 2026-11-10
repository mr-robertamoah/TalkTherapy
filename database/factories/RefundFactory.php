<?php

namespace Database\Factories;

use App\Enums\RefundStatusEnum;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'requested_by_id' => User::factory(),
            'reference' => $this->faker->unique()->uuid(),
            'amount' => $this->faker->numberBetween(1000, 100000),
            'currency' => 'GHS',
            'status' => RefundStatusEnum::pending->value,
        ];
    }
}
