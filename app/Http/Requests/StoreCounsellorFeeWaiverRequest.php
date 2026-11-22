<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFeeWaiverPeriod;

// TT-7.11e/SCRUM-475: a new commission-free period for one counsellor -- the same period rules as a platform-wide one
// (ValidatesFeeWaiverPeriod), plus an optional approved recognition of theirs to link it to (checked, under a lock, by
// PlatformFeeWaiverService). A 403 before validation for anyone but a super administrator. The counsellor is the
// route's; counsellor_id in the body is refused.
class StoreCounsellorFeeWaiverRequest extends ManagePlatformFeeWaiverRequest
{
    use ValidatesFeeWaiverPeriod;

    public function rules(): array
    {
        return [
            ...$this->periodRules(),
            'counsellor_recognition_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->periodMessages(),
            'counsellor_id.prohibited' => 'The waiver is for the counsellor in the address; counsellor_id can\'t be sent.',
        ];
    }

    public function recognitionId(): ?int
    {
        $id = $this->validated('counsellor_recognition_id');

        return $id === null ? null : (int) $id;
    }
}
