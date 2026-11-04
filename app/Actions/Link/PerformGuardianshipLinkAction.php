<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Guardianship\HandleGuardianRestoredAction;
use App\Actions\User\EnsureUserCanBeGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\CreateRequestDTO;
use App\Enums\LinkStateEnum;
use App\Exceptions\LinkException;
use App\Models\Link;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class PerformGuardianshipLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        DB::transaction(function () use ($createLinkDTO) {
            // A general link (to=null) can be used by any user, so the guardianship(guardian_id,
            // ward_id) unique index below -- keyed on the ACTING user -- doesn't stop two
            // different users both racing this same link: without a lock on the link itself,
            // both could read state=active and both attach as guardian before either commits
            // deactivate() (SCRUM-101). Locking the link row makes every racer for this link
            // serialize here, so only the first ever gets past the active-state check.
            $link = Link::query()->lockForUpdate()->findOrFail($createLinkDTO->link->id);

            if ($link->state !== LinkStateEnum::active->value) {
                throw new LinkException('This link is no longer active.', 422);
            }

            // SCRUM-288 (security review): the same eligibility as accepting a guardianship request
            // (RespondToGuardianshipRequestAction) -- adult, with a verified email.
            EnsureUserCanBeGuardianAction::new()->execute(
                CreateRequestDTO::new()->fromArray(['to' => $createLinkDTO->user]),
                'You cannot become a guardian because you are not an adult, have not set your date of birth, or have not set or verified your email.'
            );

            $ward = $createLinkDTO->link->for;

            // SCRUM-288: under the ward's lock, with locking reads (HandleGuardianRestoredAction::around()),
            // so a paused ward is told once that their therapies have started again.
            // The existence check is a courtesy for the common (sequential) case -- the
            // guardianship(guardian_id, ward_id) unique index (SCRUM-99) is what actually prevents
            // a duplicate row from the SAME user reusing this link twice. Without the catch, that
            // race would surface as an uncaught UniqueConstraintViolationException instead of the
            // same graceful "already a guardian" error the sequential case gets.
            try {
                HandleGuardianRestoredAction::new()->around($ward, HandleGuardianRestoredAction::GUARDIANSHIP_CREATED, function () use ($createLinkDTO, $ward) {
                    if ($createLinkDTO->user->wards()->where('ward_id', $ward->id)->sharedLock()->first(['id'])) {
                        throw new LinkException('You are already a guardian of this user.', 422);
                    }

                    // TT-4.10a/SCRUM-290: the same snapshot RespondToGuardianshipRequestAction writes.
                    $createLinkDTO->user->wards()->create([
                        'ward_id' => $ward->id,
                        'ward_was_minor_at_creation' => ! $ward->isAdult(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                throw new LinkException('You are already a guardian of this user.', 422);
            }

            // Deactivating on successful use (SCRUM-101) keeps this link from being replayed by
            // whoever still holds the URL -- safe from the race described above because it
            // happens inside the same lockForUpdate transaction as the active-state check.
            $link->deactivate();
        });

        return Redirect::route('profile.show');
    }
}
