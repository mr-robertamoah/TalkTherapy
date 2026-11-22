<?php

namespace App\Services;

use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\Discussion\AfterDiscussionHeldAction;
use App\Actions\Discussion\ChangeDiscussionStatusAction;
use App\Actions\Discussion\CreateDiscussionAction;
use App\Actions\Discussion\CreateDiscussionRequestAction;
use App\Actions\Discussion\DeleteDiscussionAction;
use App\Actions\Discussion\EnsureCanCreateDiscussionForAction;
use App\Actions\Discussion\EnsureCanDeleteDiscussionAction;
use App\Actions\Discussion\EnsureCanEndDiscussionAction;
use App\Actions\Discussion\EnsureCanFailDiscussionAction;
use App\Actions\Discussion\EnsureCanRemoveCounsellorFromDiscussionAction;
use App\Actions\Discussion\EnsureCanSendDiscussionRequestAction;
use App\Actions\Discussion\EnsureCanUpdateDiscussionAction;
use App\Actions\Discussion\EnsureCanUpdateDiscussionStatusAction;
use App\Actions\Discussion\EnsureDiscussionDataIsValidAction;
use App\Actions\Discussion\EnsureDiscussionExistsAction;
use App\Actions\Discussion\EnsureDoesNotHaveDiscussionRequestAction;
use App\Actions\Discussion\EnsureNotAlreadyPartOfDiscussionAction;
use App\Actions\Discussion\RemoveCounsellorFromDiscussionAction;
use App\Actions\Discussion\UpdateDiscussionAction;
use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Star\RevokeCounsellorStarsAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\Therapy\EnsureIsCounsellorAction;
use App\Actions\User\EnsureRequestDataIsValidAction;
use App\Actions\User\EnsureUserExistsAction;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateRequestDTO;
use App\DTOs\GetDiscussionsDTO;
use App\Enums\DiscussionStatusEnum;
use App\Enums\PaginationEnum;
use App\Enums\StarSourceEnum;
use App\Events\DiscussionUpdatedEvent;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\DiscussionMiniResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Notifications\DiscussionDeletedNotification;
use App\Notifications\DiscussionStatusChangedNotification;
use App\Notifications\DiscussionUpdatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DiscussionService extends Service
{
    public function removeCounsellor(GetDiscussionsDTO $getDiscussionsDTO)
    {
        EnsureUserExistsAction::new()->execute($getDiscussionsDTO->user);

        EnsureDiscussionExistsAction::new()->execute($getDiscussionsDTO);

        EnsureCanRemoveCounsellorFromDiscussionAction::new()->execute($getDiscussionsDTO);

        RemoveCounsellorFromDiscussionAction::new()->execute($getDiscussionsDTO);
    }

    public function sendCounsellorRequest(CreateRequestDTO $createRequestDTO)
    {
        // SCRUM-344: authorize (which also covers a missing discussion) before validating the rest.
        EnsureCanSendDiscussionRequestAction::new()->execute($createRequestDTO);

        EnsureRequestDataIsValidAction::new()->execute($createRequestDTO);

        EnsureNotAlreadyPartOfDiscussionAction::new()->execute($createRequestDTO);

        EnsureDoesNotHaveDiscussionRequestAction::new()->execute($createRequestDTO);

        // SCRUM-31: only a currently verified counsellor is invited in.
        EnsureCounsellorMayTakeOnClientsAction::new()->execute($createRequestDTO->to instanceof Counsellor ? $createRequestDTO->to : null, asTarget: true, forDiscussion: true);

        return CreateDiscussionRequestAction::new()->execute($createRequestDTO);
    }

    public function createDiscussion(CreateDiscussionDTO $createDiscussionDTO): Discussion
    {
        EnsureIsCounsellorAction::new()->execute($createDiscussionDTO);

        EnsureAddedbyIsValidAction::new()->execute($createDiscussionDTO, force: true);

        // SCRUM-359
        EnsureCanActAsCounsellorAction::new()->execute($createDiscussionDTO->addedby);
        EnsureCanActAsCounsellorAction::new()->execute($createDiscussionDTO->user?->counsellor);
        // SCRUM-31: and only a currently verified counsellor starts one.
        EnsureCounsellorMayTakeOnClientsAction::new()->execute($createDiscussionDTO->user?->counsellor, forDiscussion: true);

        EnsureCanCreateDiscussionForAction::new()->execute($createDiscussionDTO);

        EnsureDiscussionDataIsValidAction::new()->execute($createDiscussionDTO);

        $discussion = CreateDiscussionAction::new()->execute($createDiscussionDTO);

        return $discussion;
    }

    public function updateDiscussion(CreateDiscussionDTO $createDiscussionDTO): Discussion
    {
        EnsureIsCounsellorAction::new()->execute($createDiscussionDTO);

        EnsureDiscussionExistsAction::new()->execute($createDiscussionDTO);

        EnsureCanUpdateDiscussionAction::new()->execute($createDiscussionDTO);

        EnsureDiscussionDataIsValidAction::new()->execute($createDiscussionDTO);

        UpdateDiscussionAction::new()->execute($createDiscussionDTO);

        $discussion = $createDiscussionDTO->discussion->refresh();

        Notification::send(
            $discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionUpdatedNotification($discussion)
        );

        return $discussion;
    }

    public function endDiscussion(CreateDiscussionDTO $createDiscussionDTO)
    {
        EnsureDiscussionExistsAction::new()->execute($createDiscussionDTO);

        EnsureCanEndDiscussionAction::new()->execute($createDiscussionDTO);

        $discussion = ChangeDiscussionStatusAction::new()->execute($createDiscussionDTO, DiscussionStatusEnum::held->value);

        // TT-7.11b/SCRUM-471: the star(s) for running it, if it really took place. Never blocks ending it.
        try {
            AfterDiscussionHeldAction::new()->execute($discussion);
        } catch (Throwable $exception) {
            Log::warning('Unable to award the star for a held discussion.', [
                'discussion_id' => $discussion->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        broadcast(new DiscussionUpdatedEvent($discussion))->toOthers();

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionStatusChangedNotification($discussion)
        );

        return $discussion;
    }

    public function getInDiscussion(CreateDiscussionDTO $createDiscussionDTO)
    {
        EnsureDiscussionExistsAction::new()->execute($createDiscussionDTO);

        EnsureCanUpdateDiscussionStatusAction::new()->execute($createDiscussionDTO);

        EnsureCanActAsCounsellorAction::new()->execute($createDiscussionDTO->user?->counsellor);

        $discussion = ChangeDiscussionStatusAction::new()->execute($createDiscussionDTO, DiscussionStatusEnum::in_session->value);

        broadcast(new DiscussionUpdatedEvent($discussion))->toOthers();

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionStatusChangedNotification($discussion)
        );

        return $discussion;
    }

    public function abandonDiscussion(CreateDiscussionDTO $createDiscussionDTO)
    {
        EnsureDiscussionExistsAction::new()->execute($createDiscussionDTO);

        EnsureCanUpdateDiscussionStatusAction::new()->execute($createDiscussionDTO);

        $discussion = ChangeDiscussionStatusAction::new()->execute($createDiscussionDTO, DiscussionStatusEnum::abandoned->value);

        $this->revokeHeldStars($discussion);

        broadcast(new DiscussionUpdatedEvent($discussion))->toOthers();

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionStatusChangedNotification($discussion)
        );

        return $discussion;
    }

    public function failDiscussion(CreateDiscussionDTO $createDiscussionDTO)
    {
        EnsureDiscussionExistsAction::new()->execute($createDiscussionDTO);

        EnsureCanFailDiscussionAction::new()->execute($createDiscussionDTO);

        $discussion = ChangeDiscussionStatusAction::new()->execute($createDiscussionDTO, DiscussionStatusEnum::failed->value);

        $this->revokeHeldStars($discussion);

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionStatusChangedNotification($discussion)
        );

        return $discussion;
    }

    // TT-7.11b/SCRUM-471: a discussion that was held and later ends abandoned or failed loses its stars.
    private function revokeHeldStars(Discussion $discussion): void
    {
        try {
            RevokeCounsellorStarsAction::new()->forStarreable(StarSourceEnum::discussionHeld, $discussion);
        } catch (Throwable $exception) {
            Log::warning('Unable to revoke the stars of a discussion that ended abandoned or failed.', [
                'discussion_id' => $discussion->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function deleteDiscussion(CreateDiscussionDTO $createDiscussionDTO)
    {
        EnsureDiscussionExistsAction::new()->execute($createDiscussionDTO);

        EnsureCanDeleteDiscussionAction::new()->execute($createDiscussionDTO);

        $discussion = DeleteDiscussionAction::new()->execute($createDiscussionDTO);

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionDeletedNotification($discussion)
        );

        return $discussion;
    }

    public function getDiscussions(GetDiscussionsDTO $getDiscussionsDTO)
    {
        $counsellor = $getDiscussionsDTO->user?->counsellor;
        if (is_null($counsellor)) {
            return [];
        }

        $query = Discussion::query();

        $query->where(function ($query) use ($counsellor, $getDiscussionsDTO) {

            if ($getDiscussionsDTO->name) {
                $query->whereContains('name', $getDiscussionsDTO->name);
            }

            if ($getDiscussionsDTO->for) {
                $query->whereFor($getDiscussionsDTO->for);
            }

            $query->whereAddedby($counsellor);
        });

        $query->orWhere(function ($query) use ($counsellor, $getDiscussionsDTO) {

            if ($getDiscussionsDTO->name) {
                $query->whereContains('name', $getDiscussionsDTO->name);
            }

            if ($getDiscussionsDTO->for) {
                $query->whereFor($getDiscussionsDTO->for);
            }

            $query->whereCounsellor($counsellor);
        });

        $query->orderByDesc('updated_at');

        return DiscussionMiniResource::collection($query->paginate(PaginationEnum::pagination->value));
    }

    public function getDiscussionCounsellors(GetDiscussionsDTO $getDiscussionsDTO)
    {
        if (is_null($getDiscussionsDTO->discussion)) {
            return [];
        }

        // SCRUM-204: only an admin or a counsellor in the discussion may see its roster -- the
        // same gate DiscussionController::showChat() applies. Anyone else gets the same empty list
        // as a nonexistent discussion, so this endpoint's response says nothing either way.
        $user = $getDiscussionsDTO->user;
        if (! $user || ($user->isNotAdmin() && $getDiscussionsDTO->discussion->isNotParticipant($user->counsellor))) {
            return [];
        }

        $query = Counsellor::query();

        $query->whereDiscussion($getDiscussionsDTO->discussion);

        if ($getDiscussionsDTO->name) {
            $query->whereName($getDiscussionsDTO->name);
        }

        // CounsellorMiniResource reads ->user->username and ->avatar. avatarFile needs an
        // explicit eager load because, unlike the old FK belongsTo, this MorphToMany always
        // queries when accessed (see GetOrganizationCounsellorsAction's comment) -- user was
        // already a pre-existing, unrelated N+1 here, fixed alongside since it's the same
        // one-line pattern already used in that sibling query.
        $query->with(['user', 'avatarFile']);

        return CounsellorMiniResource::collection($query->paginate(PaginationEnum::pagination->value));
    }
}
