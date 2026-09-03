<?php

namespace Database\Factories;

use App\Enums\CounsellorPayoutStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CounsellorPayout>
 */
class CounsellorPayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'counsellor_id' => Counsellor::factory(),
            'initiated_by_id' => User::factory(),
            'reference' => 'payout_'.$this->faker->unique()->uuid(),
            'amount' => $this->faker->numberBetween(1000, 100000),
            'currency' => 'GHS',
            'status' => CounsellorPayoutStatusEnum::pending->value,
        ];
    }
}
