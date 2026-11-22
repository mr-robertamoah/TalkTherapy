<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFeeWaiverPeriod;

// TT-7.10b/SCRUM-468: a new platform-wide commission-free period (the rules are ValidatesFeeWaiverPeriod's). A 403
// before validation for anyone but a super administrator (ManagePlatformFeeWaiverRequest). counsellor_id is accepted
// only as absent or null -- a counsellor's own waiver is made from their own route (StoreCounsellorFeeWaiverRequest).
class StorePlatformFeeWaiverRequest extends ManagePlatformFeeWaiverRequest
{
    use ValidatesFeeWaiverPeriod;

    public function rules(): array
    {
        return $this->periodRules();
    }

    public function messages(): array
    {
        return [
            ...$this->periodMessages(),
            'counsellor_id.prohibited' => 'Only platform-wide periods can be created here.',
        ];
    }
}
