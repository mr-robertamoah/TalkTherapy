<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\DobChangeRequiresApprovalException;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// TT-4.10c/SCRUM-292: the shared gate invoked by BOTH ProfileController::update() (self-service)
// and UpdateUserAction (admin path) -- closes SCRUM-287's own finding that a self-editable dob
// could bypass minor-safeguarding checks, by requiring guardian/admin approval for exactly the
// edits that matter: a dob change that (a) actually changes the person's effective minor/adult
// status, in EITHER direction, AND (b) belongs to a user who already has a Guardianship-as-ward
// row or a Therapy/GroupTherapy client snapshot on file (relationship-gated scope, user's own
// explicit decision -- a user with no such relationship edits dob freely, unchanged from today).
class EnsureDobChangeIsAllowedAction extends Action
{
    public function execute(EnsureDobChangeIsAllowedDTO $dto): void
    {
        $target = $dto->user;

        if ($this->normalize($target->dob) === $this->normalize($dto->newDob)) {
            return;
        }

        if (! $this->hasQualifyingRelationship($target)) {
            return;
        }

        if ($target->isAdult() === User::isAdultForDob($dto->newDob)) {
            return;
        }

        $this->createOrReuseApprovalRequest($dto);

        throw new DobChangeRequiresApprovalException(
            'This date-of-birth change affects a minor/adult status this account already has on file, so it requires guardian or admin approval before it takes effect. A request has been submitted for review.'
        );
    }

    // $target->dob is cast to a Carbon instance by the model (App\Models\User's own 'datetime'
    // cast); $dto->newDob is a raw string straight off the request. Accepts either so the two
    // sides of the "did this actually change" comparison below can be normalized identically.
    private function normalize(Carbon|string|null $dob): ?string
    {
        return $dob ? (new Carbon($dob))->toDateString() : null;
    }

    private function hasQualifyingRelationship(User $user): bool
    {
        return $user->guardians()->exists()
            // Therapy::whereUser() is an existing, exact-match scope for this. GroupTherapy's own
            // scopeWhereUser() is deliberately NOT reused here -- it's broader (also matches any
            // participant via a pivot join), not just the self-added-client case this gate needs.
            || Therapy::query()->whereUser($user)->exists()
            || GroupTherapy::query()->where('addedby_type', User::class)->where('addedby_id', $user->id)->exists();
    }

    // Idempotent and race-safe, mirroring GrantVideoConsentAction's own find-or-create-under-lock
    // shape: locks the target User row (always exists, unlike the pending Request which may not
    // yet) before checking for an already-outstanding request, so two near-simultaneous dob-edit
    // attempts for the same user can never both create a duplicate pending request. No DB-level
    // constraint enforces this (MySQL 8's lack of partial/filtered unique indexes -- same
    // accepted limitation as video_consents, TT-3.1e-a) -- this is the sole enforcement.
    private function createOrReuseApprovalRequest(EnsureDobChangeIsAllowedDTO $dto): Request
    {
        return DB::transaction(function () use ($dto) {
            User::query()->lockForUpdate()->find($dto->user->id);

            $existing = Request::query()
                ->whereType(RequestTypeEnum::dobChange->value)
                ->wherePending()
                ->whereFor($dto->user)
                ->first();

            if ($existing) {
                // A later attempt may propose a DIFFERENT dob than whatever's already pending --
                // keep the request's own data current so a future approval acts on what the user
                // most recently asked for, not a stale first attempt. priorDob is untouched: the
                // actual stored dob hasn't changed across any of these attempts (the whole point
                // of this gate is that it never gets written while a request is outstanding).
                $existing->update(['data' => array_merge($existing->data, ['newDob' => $dto->newDob])]);

                return $existing->refresh();
            }

            // Any ONE of the ward's active guardians (mirrors GrantVideoConsentAction's own "any
            // one guardian, no unanimity" precedent) -- falls back to null (any admin may
            // respond, mirroring `refund`'s own null-`to` shape) only when no guardian exists.
            // Deliberately never both: an existing guardian is never bypassed by an
            // always-available admin route.
            $guardian = Guardianship::query()->where('ward_id', $dto->user->id)->first()?->guardian;

            return CreateRequestAction::new()->execute(
                CreateRequestDTO::new()->fromArray([
                    'from' => $dto->actor,
                    'to' => $guardian,
                    'for' => $dto->user,
                    'type' => RequestTypeEnum::dobChange->value,
                    'data' => [
                        'newDob' => $dto->newDob,
                        'priorDob' => $dto->user->dob?->toDateString(),
                    ],
                ])
            );
        });
    }
}
