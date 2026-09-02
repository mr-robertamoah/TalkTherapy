<?php

namespace Database\Factories;

use App\Enums\CounsellorEarningStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CounsellorEarning>
 */
class CounsellorEarningFactory extends Factory
{
    public function definition(): array
    {
        $gross = $this->faker->numberBetween(1000, 100000);
        $fee = (int) floor($gross * 0.1);

        return [
            'transaction_id' => Transaction::factory(),
            'counsellor_id' => Counsellor::factory(),
            'gross_amount' => $gross,
            'fee_amount' => $fee,
            'net_amount' => $gross - $fee,
            'currency' => 'GHS',
            'status' => CounsellorEarningStatusEnum::pending->value,
        ];
    }
}
