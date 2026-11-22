<?php

namespace App\Services;

use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\Discussion\AwardDiscussionHeldStarAction;
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
use App\Exceptions\DiscussionException;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\DiscussionMiniResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Notifications\DiscussionDeletedNotification;
use App\Notifications\DiscussionStatusChangedNotification;
use App\Notifications\DiscussionUpdatedNotification;
use App\Support\BestEffortAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DiscussionService extends Service
{
    public const ALREADY_ENDED = 'This discussion has already ended, so it can no longer be changed.';

    private const ENDED = [DiscussionStatusEnum::held->value, DiscussionStatusEnum::abandoned->value, DiscussionStatusEnum::failed->value];

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

        $discussion = $this->changeStatus($createDiscussionDTO, DiscussionStatusEnum::held);

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

        $discussion = $this->changeStatus($createDiscussionDTO, DiscussionStatusEnum::in_session);

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

        $discussion = $this->changeStatus($createDiscussionDTO, DiscussionStatusEnum::abandoned);

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

        $discussion = $this->changeStatus($createDiscussionDTO, DiscussionStatusEnum::failed);

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user),
            new DiscussionStatusChangedNotification($discussion)
        );

        return $discussion;
    }

    // SCRUM-471 (the user's decision): an ended discussion -- held, abandoned or failed -- is final: it can't be ended,
    // abandoned, failed or reopened again; only an administrator can change it. Checked and changed under its row lock.
    // The first time it is held (held_at, set once and never cleared) earns its stars; a held one later abandoned or
    // failed (by an administrator) loses them. Both after commit, never blocking the change.
    private function changeStatus(CreateDiscussionDTO $createDiscussionDTO, DiscussionStatusEnum $status): Discussion
    {
        $firstHeld = false;

        $discussion = DB::transaction(function () use ($createDiscussionDTO, $status, &$firstHeld) {
            $locked = Discussion::query()->whereKey($createDiscussionDTO->discussion->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, self::ENDED, true) && ! $createDiscussionDTO->user->isAdmin()) {
                throw new DiscussionException(self::ALREADY_ENDED, 422);
            }

            $createDiscussionDTO->discussion->setRawAttributes($locked->getAttributes(), true);
            $firstHeld = $status === DiscussionStatusEnum::held && ! $locked->held_at;

            $discussion = ChangeDiscussionStatusAction::new()->execute($createDiscussionDTO, $status->value);
            if ($firstHeld) {
                $discussion->forceFill(['held_at' => now()])->save();
            }

            return $discussion;
        });

        if ($firstHeld) {
            BestEffortAfterCommit::run(fn () => AwardDiscussionHeldStarAction::new()->execute($discussion));
        }

        if (in_array($status, [DiscussionStatusEnum::abandoned, DiscussionStatusEnum::failed], true)) {
            BestEffortAfterCommit::run(fn () => RevokeCounsellorStarsAction::new()->forStarreable(StarSourceEnum::discussionHeld, $discussion));
        }

        return $discussion;
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
