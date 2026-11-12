<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Enums\LinkStateEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyAssistanceLinkNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Support\LinkConfirmation;
use Illuminate\Support\Facades\DB;

// SCRUM-418: opening a therapy's counsellor link only shows the therapy as a counsellor's request card
// does (preview()); confirming makes them its counsellor (execute()). Both run the same checks; execute()
// again under the therapy's and the link's locks.
class PerformTherapyCounsellorLinkAction extends Action
{
    public const JOINED = 'You are now the counsellor of this therapy.';

    public const HAS_COUNSELLOR = 'Sorry, link cannot be used because therapy already has a counsellor';

    // What a counsellor's request card shows, masked as it masks: the name, whether it's public or
    // anonymous, when it started, and the client by the name others see. The background story only when
    // the link was addressed to them or the therapy is public (the user's decision) -- a general link can
    // be held by anyone. Read directly, not through TherapyMiniResource and its per-viewer extras
    // (pause state, card status, sponsor -- SCRUM-419).
    public function preview(CreateLinkDTO $createLinkDTO): array
    {
        $this->ensureCounsellor($createLinkDTO);

        $therapy = $this->therapyOf($createLinkDTO->link);

        $this->ensureCanTakeOn($therapy);

        $to = $createLinkDTO->link->to;
        $addressed = $to && ($to->is($createLinkDTO->user) || $to->is($createLinkDTO->user->counsellor));

        return [
            'name' => $therapy->name,
            'public' => $therapy->public,
            'anonymous' => $therapy->anonymous,
            'createdAt' => $therapy->created_at->diffForHumans(),
            'client' => $therapy->addedby instanceof User ? $therapy->displayNameOf($therapy->addedby) : null,
            'backgroundStory' => $addressed || $therapy->public ? $therapy->background_story : null,
        ];
    }

    public function execute(CreateLinkDTO $createLinkDTO): LinkConfirmation
    {
        $this->ensureCounsellor($createLinkDTO);

        // Locking the therapy row before deciding whether it already has a counsellor closes
        // the same TOCTOU race SCRUM-91 fixed for RespondToTherapyAssistanceRequestAction: two
        // concurrent uses of a therapy-counsellor link (or one racing a request-based accept)
        // could otherwise both see no counsellor assigned yet and both "win", losing one of the
        // two counsellor_id writes (SCRUM-98). Locking the therapy row before touching any
        // Request row (here, the sibling-invalidation update below) keeps this on the same
        // therapy-before-request lock order as RespondToTherapyAssistanceRequestAction, so the
        // two code paths can never deadlock against each other.
        $therapy = DB::transaction(function () use ($createLinkDTO) {
            $therapy = Therapy::query()->lockForUpdate()->findOrFail($this->therapyOf($createLinkDTO->link)->id);

            // SCRUM-418: the link too, and still unused -- it used to be marked used without being re-read,
            // leaving only the therapy's lock against a replay. After the therapy (the other link types lock
            // their link first): this path has always locked the therapy first, as
            // RespondToTherapyAssistanceRequestAction does, and nothing else locks a therapy-counsellor link.
            $link = Link::query()->lockForUpdate()->find($createLinkDTO->link->id);

            if (! $link || $link->state !== LinkStateEnum::active->value) {
                throw LinkException::inactive();
            }

            $this->ensureCanTakeOn($therapy);

            $therapy->update([
                'counsellor_id' => $createLinkDTO->user->counsellor->id,
            ]);

            Request::query()
                ->wherePending()
                ->whereFor($therapy)
                ->update([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'responded_at' => now(),
                    'data' => [
                        'reason' => 'A similar request for therapy assistance has been accepted by someone else.',
                    ],
                ]);

            // Marking it used (SCRUM-101; used_at, SCRUM-415) keeps this link from being replayed --
            // done inside the same transaction so a rollback (e.g. the already-has-a-counsellor
            // throw above) never leaves the link deactivated without an actual assignment.
            $link->markUsed();

            return $therapy->refresh();
        });

        $createLinkDTO->link->addedby->notify(
            new TherapyAssistanceLinkNotification($therapy)
        );

        // SCRUM-358: always the therapy client's guardians (SCRUM-350 makes the link's creator the
        // client anyway, but the alert must never follow anyone else).
        if ($therapy->addedby instanceof User) {
            AlertGuardianAction::new()->execute(
                GuardianAlertDTO::new()->fromArray([
                    'user' => $therapy->addedby,
                    'notification' => new TherapyAssistanceRequestAcceptedGuardianNotification($therapy),
                    'for' => $therapy,
                ])
            );
        }

        return new LinkConfirmation(self::JOINED, route('therapies.get', ['therapyId' => $therapy->id]));
    }

    private function ensureCounsellor(CreateLinkDTO $createLinkDTO): void
    {
        // Without this, a non-counsellor user reaching this action (e.g. via a bug elsewhere
        // in the auth chain) would hit an uncaught Error dereferencing a null ->counsellor
        // below, instead of a clean LinkException -- SCRUM-101.
        EnsureCounsellorExistsAction::new()->execute(
            UpdateCounsellorDTO::new()->fromArray(['counsellor' => $createLinkDTO->user?->counsellor]),
            LinkException::NO_COUNSELLOR
        );

        // SCRUM-31: only a currently verified counsellor takes on a client.
        EnsureCounsellorMayTakeOnClientsAction::new()->execute($createLinkDTO->user->counsellor);
    }

    // Still without a counsellor, and not paused for its minor client's missing guardian (SCRUM-288) --
    // told neutrally (SCRUM-418 security review): whoever holds a general link isn't told the client is a
    // minor without a guardian, as with a group's links (PerformGroupTherapyLinkAction).
    private function ensureCanTakeOn(Therapy $therapy): void
    {
        if ($therapy->counsellor) {
            throw new LinkException(self::HAS_COUNSELLOR, 422);
        }

        if ($therapy->addedby instanceof User && $therapy->addedby->isPausedForMissingGuardian(fresh: true)) {
            throw GuardianRequiredException::linkUnavailable();
        }
    }

    private function therapyOf(Link $link): Therapy
    {
        if (! $link->for instanceof Therapy) {
            throw LinkException::inactive();
        }

        return $link->for;
    }
}
