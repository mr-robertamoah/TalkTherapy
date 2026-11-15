<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Exceptions\SessionException;
use App\Support\InPerson;
use Carbon\Carbon;

class EnsureSessionScheduleProposalDataIsValidAction extends Action
{
    // Sanity-checks only -- a slot that passes this can still fail the real double-booking/
    // max-sessions checks (EnsureSessionDataIsValidAction) at accept-time (TT-2.5b), since
    // availability can change between propose and accept. Re-running that full check here would
    // only prove the slot was valid *right now*, not at whatever later moment it's eventually
    // accepted. Payment type IS fully enforced here, not deferred -- see below.
    public function execute(SessionScheduleProposalDTO $dto)
    {
        if (! $dto->startTime || ! $dto->endTime) {
            throw new SessionException('A proposed start and end time are required.', 422);
        }

        $startTime = Carbon::parse($dto->startTime);
        $endTime = Carbon::parse($dto->endTime);

        if ($startTime->isPast()) {
            throw new SessionException('The proposed start time cannot be in the past.', 422);
        }

        if ($startTime->copy()->addMinutes(30)->greaterThan($endTime)) {
            throw new SessionException('The proposed end time must be at least 30 minutes from the start time.', 422);
        }

        $this->ensurePaymentTypeMatchesTherapy($dto);

        $this->ensureInPersonIsValid($dto);
    }

    // SCRUM-443 (the user's decision): an in-person proposal is refused up front for what the
    // therapy itself rules out -- in-person not allowed, or a client under 18 -- and must carry its
    // place (a counter-offer keeps the place of the proposal it supersedes). Accepting re-checks all
    // of it through EnsureSessionDataIsValidAction, since either can change in between. Runs after
    // the participancy check, like the payment-type check above, so it discloses nothing to outsiders.
    private function ensureInPersonIsValid(SessionScheduleProposalDTO $dto): void
    {
        $therapy = $dto->therapy ?? $dto->request?->for;
        $current = $dto->request?->data ?? [];

        if (($dto->type ?: $current['type'] ?? null) !== SessionTypeEnum::in_person->value || ! $therapy) {
            return;
        }

        if (! $therapy->allow_in_person) {
            throw new SessionException('In-person sessions aren\'t allowed for this therapy.', 422);
        }

        if (InPerson::hasMinorClient($therapy)) {
            throw new SessionException(InPerson::refusalFor($dto->user, $therapy), 422);
        }

        [$landmark, $latitude, $longitude] = self::placeOf($dto);

        if (is_null($latitude) || is_null($longitude) || mb_strlen((string) $landmark) < 3) {
            throw new SessionException('An in-person session needs a place: pick it on the map and describe it (for example "Room 3, 2nd floor").', 422);
        }
    }

    // The place a proposal will carry: what it sends, else (for a counter-offer) the superseded one's.
    public static function placeOf(SessionScheduleProposalDTO $dto): array
    {
        $current = $dto->request?->data ?? [];
        $landmark = filled($dto->landmark) ? trim($dto->landmark) : ($current['landmark'] ?? null);

        return [$landmark, $dto->latitude ?? $current['latitude'] ?? null, $dto->longitude ?? $current['longitude'] ?? null];
    }

    // Security review (SCRUM-208): EnsureCanProposeSessionScheduleAction now lets EITHER
    // participant propose, including the client -- who, unlike the counsellor/admin trusted with
    // this same field on a direct session create (EnsureCanCreateSessionAction), has a direct
    // financial incentive to under-report. A client-supplied paymentType must always match the
    // therapy's own -- there's no legitimate reason it would ever differ -- and, since a fresh
    // proposal has no prior negotiated value to fall back to (unlike a counter-offer, which falls
    // back to the already-validated current proposal's data), a PAID therapy's first proposal must
    // state it explicitly rather than silently default. This intentionally runs here, AFTER
    // EnsureCanProposeSessionScheduleAction's participancy check, not as a therapy-dependent
    // FormRequest rule -- a validation rule keyed on the therapy's own payment_type would run
    // before that authorization check and leak whether an arbitrary, including non-participant,
    // therapy is PAID via the presence/absence of a validation error (the same
    // enumeration class already fixed for this request family, SCRUM-124/162/206).
    private function ensurePaymentTypeMatchesTherapy(SessionScheduleProposalDTO $dto)
    {
        $therapy = $dto->therapy ?? $dto->request?->for;

        if (! $therapy) {
            return;
        }

        if ($dto->paymentType && $dto->paymentType !== $therapy->payment_type) {
            throw new SessionException("This therapy's payment type is {$therapy->payment_type}; a proposed session must match it.", 422);
        }

        $isFreshProposal = is_null($dto->request);

        if ($isFreshProposal && $therapy->payment_type === TherapyPaymentTypeEnum::paid->value && ! $dto->paymentType) {
            throw new SessionException('A payment type of PAID is required to propose a session for a paid therapy.', 422);
        }
    }
}
