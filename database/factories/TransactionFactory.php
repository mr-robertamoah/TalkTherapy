<?php

namespace Database\Factories;

use App\Enums\TransactionStatusEnum;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference' => $this->faker->unique()->uuid(),
            'amount' => $this->faker->numberBetween(1000, 100000),
            'currency' => 'GHS',
            'status' => TransactionStatusEnum::pending->value,
        ];
    }
}
