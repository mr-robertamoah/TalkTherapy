<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Enums\LinkStateEnum;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Models\Therapy;
use App\Notifications\DiscussionInclusionNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class PerformDiscussionRequestLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        EnsureCounsellorExistsAction::new()->execute(
            UpdateCounsellorDTO::new()->fromArray(['counsellor' => $createLinkDTO->user?->counsellor]),
            'You do not have a counsellor account hence you are not authorized to use this link. Create a counsellor account first.'
        );

        DB::transaction(function () use ($createLinkDTO) {
            // A general link (to=null) can be used by any counsellor, so the
            // counsellor_discussion(counsellor_id, discussion_id) unique index below -- keyed on
            // the ACTING counsellor -- doesn't stop two different counsellors both racing this
            // same link: without a lock on the link itself, both could read state=active and
            // both attach before either commits deactivate() (SCRUM-101). Locking the link row
            // makes every racer for this link serialize here, so only the first ever gets past
            // the active-state check.
            $link = Link::query()->lockForUpdate()->findOrFail($createLinkDTO->link->id);

            if ($link->state !== LinkStateEnum::active->value) {
                throw new LinkException('This link is no longer active.', 422);
            }

            if (
                $createLinkDTO->link->for
                    ->counsellors()
                    ->where('counsellor_id', $createLinkDTO->user->counsellor->id)
                    ->exists()
            ) {
                throw new LinkException('You cannot use link because you are already part of this discussion.', 422);
            }

            // The existence check above is a courtesy for the common (sequential) case -- the
            // counsellor_discussion(counsellor_id, discussion_id) unique index (SCRUM-100) is what
            // actually prevents a duplicate pivot row from the SAME counsellor reusing this link
            // twice. Without this catch, that race would surface as an uncaught
            // UniqueConstraintViolationException instead of the same graceful "already part of this
            // discussion" error the sequential case gets.
            try {
                $createLinkDTO->link->for->counsellors()->attach($createLinkDTO->user->counsellor->id);
            } catch (UniqueConstraintViolationException) {
                throw new LinkException('You cannot use link because you are already part of this discussion.', 422);
            }

            // Deactivating on successful use (SCRUM-101) keeps this link from being replayed by
            // whoever still holds the URL -- safe from the race described above because it
            // happens inside the same lockForUpdate transaction as the active-state check.
            $link->deactivate();
        });

        $createLinkDTO->link->for->addedby->notify(
            new DiscussionInclusionNotification($createLinkDTO->user->counsellor, $createLinkDTO->link->for)
        );

        if ($createLinkDTO->link->for->for_type == Therapy::class) {
            return Redirect::route('therapies.get', ['therapyId' => $createLinkDTO->link->for->for_id]);
        }

        return Redirect::route('home');
    }
}
