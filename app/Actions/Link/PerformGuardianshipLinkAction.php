<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
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

            if (
                $createLinkDTO->user
                    ->wards()
                    ->where('ward_id', $createLinkDTO->link->for->id)
                    ->exists()
            ) {
                throw new LinkException('You are already a guardian of this user.', 422);
            }

            // The existence check above is a courtesy for the common (sequential) case -- the
            // guardianship(guardian_id, ward_id) unique index (SCRUM-99) is what actually prevents
            // a duplicate row from the SAME user reusing this link twice. Without this catch,
            // that race would surface as an uncaught UniqueConstraintViolationException instead
            // of the same graceful "already a guardian" error the sequential case gets.
            try {
                $createLinkDTO->user->wards()->create(['ward_id' => $createLinkDTO->link->for->id]);
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
