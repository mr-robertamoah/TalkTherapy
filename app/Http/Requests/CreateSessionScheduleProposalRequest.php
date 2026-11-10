<?php

namespace App\Http\Requests;

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSessionScheduleProposalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'startTime' => ['required', 'date'],
            'endTime' => ['required', 'date'],
            // sessions.name/about are both NOT NULL -- required here so accept-time session
            // creation (TT-2.5b) never fails on a missing column, matching CreateSessionRequest's
            // own rules. Unlike type/paymentType below, neither has a sensible default (a session
            // needs an explicit name/description the same way a directly-created one does), so
            // both stay required rather than defaulted server-side (found via a regression test,
            // SCRUM-208, after the identical gap for `about` was already fixed once in SCRUM-207).
            'name' => ['required', 'string', 'max:255'],
            'about' => ['required', 'string'],
            // Matches CreateSessionRequest's identical fields -- this data is persisted verbatim
            // into requests.data and is what TT-2.5b's accept step will eventually feed straight
            // into CreateSessionAction, so it must be valid now, not just well-typed. Both left
            // nullable here (unlike CreateSessionRequest's unconditional 'required') because
            // ProposeSessionScheduleAction defaults them for a FREE/no-in-person therapy the same
            // way CreateSessionFormModal.vue does client-side for a direct session create.
            //
            // Deliberately NOT a Rule::requiredIf() keyed on the therapy's own payment_type here
            // (security review, SCRUM-208): that would run a DB lookup on the therapy BEFORE
            // EnsureCanProposeSessionScheduleAction's participancy check ever runs (FormRequest
            // validation always precedes the controller body), letting any authenticated user
            // enumerate whether an arbitrary, including private/non-participant, therapy is PAID
            // just from whether this field gets marked required -- the exact PII/data-enumeration
            // class already fixed once for this request family (SCRUM-124/162/206). The
            // required-for-PAID and must-match-the-therapy's-own-type checks are instead enforced
            // in EnsureSessionScheduleProposalDataIsValidAction, which runs after that
            // authorization check, for both propose and counter-offer.
            'type' => ['nullable', Rule::in(SessionTypeEnum::values())],
            'paymentType' => ['nullable', Rule::in(TherapyPaymentTypeEnum::values())],
            'expiryDays' => ['nullable', 'integer', 'between:1,30'],
        ];
    }
}
