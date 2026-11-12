<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\Discussion\EnsureDiscussionCanAcceptCounsellorAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetDiscussionsDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Enums\LinkStateEnum;
use App\Exceptions\DiscussionException;
use App\Exceptions\LinkException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Link;
use App\Models\Therapy;
use App\Notifications\DiscussionInclusionNotification;
use App\Support\LinkConfirmation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// SCRUM-418: opening a discussion link only shows the discussion (preview()); confirming adds the
// counsellor to it (execute()). Both run the same checks (ensureCanJoin()); execute() again under the
// link's and the discussion's locks. Afterwards they go to the discussion's chat -- the therapy's page
// it is about isn't theirs to open.
class PerformDiscussionRequestLinkAction extends Action
{
    public const JOINED = 'You have joined the discussion.';

    public const ALREADY_IN = 'You cannot use link because you are already part of this discussion.';

    public const FULL = 'This discussion already has its maximum number of counsellors.';

    // The discussion's name, description and times, the counsellor who started it, and whether it is
    // about a therapy or a group -- never the client, or the therapy's or group's own name. Read directly,
    // not through a card's resource and its per-viewer extras (SCRUM-419).
    public function preview(CreateLinkDTO $createLinkDTO): array
    {
        $this->ensureCounsellor($createLinkDTO);

        $discussion = $this->discussionOf($createLinkDTO->link);

        $this->ensureCanJoin($discussion, $createLinkDTO->user->counsellor);

        return [
            'name' => $discussion->name,
            'description' => $discussion->description,
            'startTime' => $discussion->start_time,
            'endTime' => $discussion->end_time,
            'startedBy' => $discussion->addedby instanceof Counsellor ? $discussion->addedby->getName() : null,
            'about' => $discussion->for_type === Therapy::class ? 'therapy' : 'group',
        ];
    }

    public function execute(CreateLinkDTO $createLinkDTO): LinkConfirmation
    {
        $this->ensureCounsellor($createLinkDTO);

        $discussion = DB::transaction(function () use ($createLinkDTO) {
            // A general link (to=null) can be used by any counsellor, so the
            // counsellor_discussion(counsellor_id, discussion_id) unique index below -- keyed on
            // the ACTING counsellor -- doesn't stop two different counsellors both racing this
            // same link: without a lock on the link itself, both could read state=active and
            // both attach before either commits markUsed() (SCRUM-101). Locking the link row
            // makes every racer for this link serialize here, so only the first ever gets past
            // the active-state check.
            $link = Link::query()->lockForUpdate()->findOrFail($createLinkDTO->link->id);

            if ($link->state !== LinkStateEnum::active->value) {
                throw new LinkException('This link is no longer active.', 422);
            }

            // Locking the Discussion row here (in addition to the Link row already locked above)
            // is what makes two different counsellors racing this same link -- or a second link
            // for the same discussion -- serialize against each other for the capacity check
            // below, not just against duplicate use of this one link. See
            // EnsureDiscussionCanAcceptCounsellorAction's comment (SCRUM-23/TT-2.4).
            $discussion = Discussion::query()->lockForUpdate()->findOrFail($this->discussionOf($link)->id);

            $this->ensureCanJoin($discussion, $createLinkDTO->user->counsellor, lock: true);

            // The existence check above is a courtesy for the common (sequential) case -- the
            // counsellor_discussion(counsellor_id, discussion_id) unique index (SCRUM-100) is what
            // actually prevents a duplicate pivot row from the SAME counsellor reusing this link
            // twice. Without this catch, that race would surface as an uncaught
            // UniqueConstraintViolationException instead of the same graceful "already part of this
            // discussion" error the sequential case gets.
            try {
                $discussion->counsellors()->attach($createLinkDTO->user->counsellor->id);
            } catch (UniqueConstraintViolationException) {
                throw new LinkException(self::ALREADY_IN, 422);
            }

            // Marking it used (SCRUM-101; used_at, SCRUM-415) keeps this link from being replayed by
            // whoever still holds the URL -- safe from the race described above because it
            // happens inside the same lockForUpdate transaction as the active-state check.
            $link->markUsed();

            return $discussion;
        });

        $discussion->addedby->notify(
            new DiscussionInclusionNotification($createLinkDTO->user->counsellor, $discussion)
        );

        return new LinkConfirmation(self::JOINED, route('discussions.chat', ['discussionId' => $discussion->id]));
    }

    private function ensureCounsellor(CreateLinkDTO $createLinkDTO): void
    {
        EnsureCounsellorExistsAction::new()->execute(
            UpdateCounsellorDTO::new()->fromArray(['counsellor' => $createLinkDTO->user?->counsellor]),
            LinkException::NO_COUNSELLOR
        );

        // SCRUM-31: only a currently verified counsellor joins.
        EnsureCounsellorMayTakeOnClientsAction::new()->execute($createLinkDTO->user->counsellor, forDiscussion: true);
    }

    // Not already in it, and room for another counsellor. $lock: a locking read, under the discussion's lock
    // (see EnsureDiscussionCanAcceptCounsellorAction's comment on REPEATABLE-READ staleness, SCRUM-23) --
    // the capacity count always is one. The capacity refusal doesn't repeat the discussion's name.
    private function ensureCanJoin(Discussion $discussion, Counsellor $counsellor, bool $lock = false): void
    {
        if ($discussion->counsellors()->when($lock, fn ($query) => $query->lockForUpdate())->where('counsellor_id', $counsellor->id)->exists()) {
            throw new LinkException(self::ALREADY_IN, 422);
        }

        try {
            EnsureDiscussionCanAcceptCounsellorAction::new()->execute(
                GetDiscussionsDTO::new()->fromArray(['discussion' => $discussion])
            );
        } catch (DiscussionException) {
            throw new LinkException(self::FULL, 422);
        }
    }

    private function discussionOf(Link $link): Discussion
    {
        if (! $link->for instanceof Discussion) {
            throw LinkException::inactive();
        }

        return $link->for;
    }
}
