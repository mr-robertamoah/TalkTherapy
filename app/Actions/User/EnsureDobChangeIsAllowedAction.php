<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\DobChangeRequiresApprovalException;
use App\Models\GroupTherapy;
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

        if (! $this->hasQualifyingRelationship($target, $dto->actor)) {
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

    // SCRUM-288 (security review): also a group membership (not just a created group), anyone who was
    // ever a ward (a guardianship now, or one accepted through a request), and anyone paused for a
    // missing guardian right now -- otherwise a paused minor could edit their way to 18 and un-pause.
    // A first date of birth on an account with none of these (signing up) stays free, and so does an
    // administrator's edit of a paused account with no other relationship.
    private function hasQualifyingRelationship(User $user, ?User $actor): bool
    {
        return $user->guardians()->exists()
            || Request::query()
                ->where('type', RequestTypeEnum::guardianship->value)
                ->where('status', RequestStatusEnum::accepted->value)
                ->where('from_type', User::class)
                ->where('from_id', $user->id)
                ->exists()
            // Therapy::whereUser() is an exact-match scope for the client; GroupTherapy::whereUser()
            // matches the creator and any member.
            || Therapy::query()->whereUser($user)->exists()
            || GroupTherapy::query()->whereUser($user)->exists()
            // SCRUM-381: a minor carrying on under a safeguarding exception is not paused, so the
            // line below wouldn't catch them -- any exception, ever, counts as a relationship.
            || $user->safeguardingExceptions()->exists()
            // Only their own edit: an administrator editing it is the approver such a request
            // would go to anyway (no active guardian, so `to` is any admin).
            || ($user->dob && (! $actor || $actor->is($user)) && $user->isPausedForMissingGuardian(fresh: true));
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
            // SCRUM-288: only a guardian who counts (Guardianship::scopeActive) -- a suspended, deleted
            // or under-age one can't approve.
            $guardian = $dto->user->activeGuardianships()->first()?->guardian;

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
