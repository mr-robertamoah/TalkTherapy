<?php

namespace Database\Factories;

use App\Enums\PayoutDestinationTypeEnum;
use App\Models\Counsellor;
use App\Models\CounsellorPayoutAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CounsellorPayoutAccount>
 */
class CounsellorPayoutAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'counsellor_id' => Counsellor::factory(),
            'type' => PayoutDestinationTypeEnum::nuban->value,
            'bank_code' => '057',
            'bank_name' => 'Test Bank',
            'account_name' => $this->faker->name,
            'masked_account_number' => '**** '.$this->faker->numerify('####'),
            'recipient_code' => 'RCP_'.$this->faker->unique()->uuid(),
            'currency' => 'GHS',
        ];
    }
}
