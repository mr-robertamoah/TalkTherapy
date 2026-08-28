<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;
use App\Models\Counsellor;

class EnsureTherapyDataIsValidAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;

        if (
            $therapy &&
            $therapy->status == TherapyStatusEnum::ended->value
        ) {
            throw new TherapyCreationDataIsNotValidException('You cannot update a therapy which has ended.', 422);
        }

        if (
            $therapy?->sessionsHeld &&
            (
                ($dto->sessionType && $therapy->session_type !== $dto->sessionType) ||
                ($dto->paymentType && $therapy->payment_type !== $dto->paymentType)
            )
        ) {
            throw new TherapyCreationDataIsNotValidException('You cannot change payment type (PAID, FREE) or session type (ONCE, PERIODIC) once there have been at least one session held.', 422);
        }

        // SCRUM-132: every cross-field check below reasons about the RESULTING state of the
        // therapy after this request applies, not just the fields this particular (possibly
        // partial) request happens to mention. Falling back to $therapy's persisted value when a
        // field is omitted avoids both directions of the bug this ticket described: a partial
        // update re-triggering a check for a field it never touched (false positive), and a
        // partial update skipping a check because the field it's cross-validated against wasn't
        // resent even though it's already in a state that would fail the check (bypass). On
        // create, $therapy is null and every field is required anyway, so these are equivalent to
        // the DTO's own values.
        $effectiveSessionType = $dto->sessionType ?? $therapy?->session_type;
        $effectivePaymentType = $dto->paymentType ?? $therapy?->payment_type;
        $effectiveMaxSessions = $dto->maxSessions ?? $therapy?->max_sessions;
        $effectivePublic = $dto->public ?? $therapy?->public;
        $effectiveAmount = $dto->amount ?? data_get($therapy?->payment_data, 'amount');
        $effectiveCurrency = $dto->currency ?? data_get($therapy?->payment_data, 'currency');
        $effectivePer = $dto->per ?? data_get($therapy?->payment_data, 'per');
        $effectiveInPersonAmount = $dto->inPersonAmount ?? data_get($therapy?->payment_data, 'inPersonAmount');

        if (
            $effectiveSessionType == TherapySessionTypeEnum::periodic->value &&
            (! $effectiveMaxSessions || $effectiveMaxSessions < 2)
        ) {
            throw new TherapyCreationDataIsNotValidException('Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2.', 422);
        }

        // Mirrors the max_users/max_counsellors sanity ceiling below (SCRUM-84) -- maxSessions
        // had no upper bound at all, for either an individual Therapy or a GroupTherapy.
        //
        // The `$therapy && $dto->maxSessions == $therapy->max_sessions` bypass (SCRUM-88) only
        // applies once $therapy already exists (i.e. on update, never on create): a record
        // created before this ceiling existed (or before an env var lowered it) could already
        // be sitting above it, and an edit form that resends the current, unchanged value
        // alongside an unrelated field change must not get rejected for a value the user never
        // touched -- mirroring how session_type/payment_type are already only rejected above
        // when they actually differ from what's stored, not just because they were resent.
        // `===` (not `==`) to match this method's other "did this field actually change" checks
        // -- both sides are always a genuine int here (DTOTrait force-casts typed ?int
        // properties, and these are plain SQL integer columns), so there's no numeric-string
        // case to loosen for.
        $maxSessions = env('THERAPY_MAX_SESSIONS', 100);
        if (
            $dto->maxSessions > $maxSessions &&
            ! ($therapy && $dto->maxSessions === $therapy->max_sessions)
        ) {
            throw new TherapyCreationDataIsNotValidException("Your sessions cannot be more than {$maxSessions}.", 422);
        }

        if (
            $effectivePaymentType == TherapyPaymentTypeEnum::paid->value &&
            ! ($effectiveAmount && $effectiveCurrency && $effectivePer)
        ) {
            throw new TherapyCreationDataIsNotValidException('Amount, currency and per what? All of these are required since you selected PAID payment type.', 422);
        }

        // Deliberately not gated on $effectivePaymentType (unlike the checks around it) -- both
        // values are only ever non-null when payment_data actually holds them, so this is a
        // pure amount-vs-amount comparison regardless of payment type.
        if (
            $effectiveInPersonAmount && $effectiveAmount &&
            $effectiveInPersonAmount < $effectiveAmount
        ) {
            throw new TherapyCreationDataIsNotValidException('Amount in-person session cannot be less than amount for online session.', 422);
        }

        if (
            $effectivePaymentType == TherapyPaymentTypeEnum::free->value &&
            ! $effectivePublic
        ) {
            throw new TherapyCreationDataIsNotValidException('FREE payment types requires that you make therapy PUBLIC.', 422);
        }

        if (
            $effectivePaymentType == TherapyPaymentTypeEnum::paid->value &&
            $effectiveSessionType == TherapySessionTypeEnum::once->value &&
            $effectivePer !== TherapyPerPaymentEnum::therapy->value
        ) {
            throw new TherapyCreationDataIsNotValidException('Since ONCE and PAID have been selected for session and payment types respectively, the amount should be per THERAPY.', 422);
        }

        if (! (
            $dto::class == GroupTherapyDTO::class
        )) {
            return;
        }

        // See the maxSessions bypass above (SCRUM-88) for why an unchanged, already-over-ceiling
        // value must not block an unrelated edit.
        $maxCounsellors = env('GROUP_THERAPY_MAX_COUNSELLORS', 10);
        if (
            $dto->maxCounsellors > $maxCounsellors &&
            ! ($therapy && $dto->maxCounsellors === $therapy->max_counsellors)
        ) {
            throw new TherapyCreationDataIsNotValidException("Your counsellors cannot be more than {$maxCounsellors}.", 422);
        }

        $maxUsers = env('GROUP_THERAPY_MAX_USERS', 50);
        if (
            $dto->maxUsers > $maxUsers &&
            ! ($therapy && $dto->maxUsers === $therapy->max_users)
        ) {
            throw new TherapyCreationDataIsNotValidException("Your users cannot be more than {$maxUsers}.", 422);
        }

        if (! (
            $effectivePaymentType == TherapyPaymentTypeEnum::paid->value
        )) {
            return;
        }

        // $dto->counsellor is only ever populated on create (the DTO field represents who is
        // creating the group therapy, from the request's own counsellorId -- there's no
        // "resend the current counsellor" concept on update since GroupTherapyController's
        // update path never repopulates it). On update, fall back to whether the persisted
        // record was itself created by a counsellor (the same addedby_type check GroupTherapy's
        // own isCounsellor()/getCounsellors() use elsewhere), so a partial update to
        // sharePercentage alone doesn't get evaluated as if no counsellor were involved at all.
        $effectiveIsCounsellorOwned = $dto->counsellor !== null
            ? true
            : ($therapy && $therapy->addedby_type === Counsellor::class);
        $effectiveShareEqually = $dto->shareEqually ?? data_get($therapy?->payment_data, 'shareEqually');
        $effectiveSharePercentage = $dto->sharePercentage ?? data_get($therapy?->payment_data, 'sharePercentage');

        if (
            $effectiveIsCounsellorOwned &&
            ! $effectiveShareEqually &&
            (
                ! $effectiveSharePercentage ||
                $effectiveSharePercentage > 100 ||
                $effectiveSharePercentage < 40
            )
        ) {
            throw new TherapyCreationDataIsNotValidException('The share to counsellors cannot be more than 100% or below 40%.', 422);
        }

        if (
            ! $effectiveIsCounsellorOwned &&
            $effectiveShareEqually
        ) {
            throw new TherapyCreationDataIsNotValidException('As a user, you cannot share equally with counsellors at the momemnt. You can have a maximum of 30%.', 422);
        }

        if (
            ! $effectiveIsCounsellorOwned &&
            (
                ! $effectiveSharePercentage ||
                $effectiveSharePercentage < 70
            )
        ) {
            throw new TherapyCreationDataIsNotValidException('As a user, your share of group therapy cannot go beyound 30%. Hence the share percentage for counsellors must be 70% or higher.', 422);
        }
    }
}
