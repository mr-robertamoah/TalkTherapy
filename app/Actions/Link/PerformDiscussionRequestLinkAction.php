<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Exceptions\LinkException;
use App\Models\Therapy;
use App\Notifications\DiscussionInclusionNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Redirect;

class PerformDiscussionRequestLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        EnsureCounsellorExistsAction::new()->execute(
            UpdateCounsellorDTO::new()->fromArray(['counsellor' => $createLinkDTO->user?->counsellor]),
            'You do not have a counsellor account hence you are not authorized to use this link. Create a counsellor account first.'
        );

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
        // actually prevents a duplicate pivot row from two uses of this same link racing each
        // other. Without this catch, that race would surface as an uncaught
        // UniqueConstraintViolationException instead of the same graceful "already part of this
        // discussion" error the sequential case gets.
        try {
            $createLinkDTO->link->for->counsellors()->attach($createLinkDTO->user->counsellor->id);
        } catch (UniqueConstraintViolationException) {
            throw new LinkException('You cannot use link because you are already part of this discussion.', 422);
        }

        $createLinkDTO->link->for->addedby->notify(
            new DiscussionInclusionNotification($createLinkDTO->user->counsellor, $createLinkDTO->link->for)
        );

        if ($createLinkDTO->link->for->for_type == Therapy::class) {
            return Redirect::route('therapies.get', ['therapyId' => $createLinkDTO->link->for->for_id]);
        }

        return Redirect::route('home');
    }
}
