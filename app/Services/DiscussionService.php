<?php

namespace App\Services;

use App\Actions\Discussion\ChangeDiscussionStatusAction;
use App\Actions\Discussion\CreateDiscussionAction;
use App\Actions\Discussion\DeleteDiscussionAction;
use App\Actions\Discussion\EnsureCanDeleteDiscussionAction;
use App\Actions\Discussion\EnsureCanEndDiscussionAction;
use App\Actions\Discussion\EnsureCanUpdateDiscussionAction;
use App\Actions\Discussion\EnsureCanUpdateDiscussionStatusAction;
use App\Actions\Discussion\EnsureDiscussionDataIsValidAction;
use App\Actions\Discussion\EnsureDiscussionExistsAction;
use App\Actions\Discussion\EnsureDoesNotHaveDiscussionRequestAction;
use App\Actions\Discussion\EnsureNotAlreadyPartOfDiscussionAction;
use App\Actions\Discussion\UpdateDiscussionAction;
use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\EnsureIsCounsellorAction;
use App\Actions\Discussion\CreateDiscussionRequestAction;
use App\Actions\Discussion\EnsureCanRemoveCounsellorFromDiscussionAction;
use App\Actions\Discussion\RemoveCounsellorFromDiscussionAction;
use App\Actions\User\EnsureRequestDataIsValidAction;
use App\Actions\User\EnsureUserExistsAction;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateRequestDTO;
use App\DTOs\CreateStarDTO;
use App\DTOs\GetDiscussionsDTO;
use App\Enums\DiscussionStatusEnum;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Events\DiscussionUpdatedEvent;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\DiscussionMiniResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Notifications\DiscussionDeletedNotification;
use App\Notifications\DiscussionStatusChangedNotification;
use App\Notifications\DiscussionUpdatedNotification;
use Illuminate\Support\Facades\Notification;

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
        EnsureRequestDataIsValidAction::new()->execute($createRequestDTO);
        
        EnsureNotAlreadyPartOfDiscussionAction::new()->execute($createRequestDTO);
        
        EnsureDoesNotHaveDiscussionRequestAction::new()->execute($createRequestDTO);
        
        return CreateDiscussionRequestAction::new()->execute($createRequestDTO);
    }

    public function createDiscussion(CreateDiscussionDTO $createDiscussionDTO) : Discussion
    {
        EnsureIsCounsellorAction::new()->execute($createDiscussionDTO);

        EnsureAddedbyIsValidAction::new()->execute($createDiscussionDTO, force: true);

        EnsureDiscussionDataIsValidAction::new()->execute($createDiscussionDTO);

        $discussion = CreateDiscussionAction::new()->execute($createDiscussionDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createDiscussionDTO->addedby,
                'starreable' => $discussion,
                'type' => StarTypeEnum::participation->value,
            ])
        );

        return $discussion;
    }
    
    public function updateDiscussion(CreateDiscussionDTO $createDiscussionDTO) : Discussion
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

        EnsureCanEndDiscussionAction::new()->execute($createDiscussionDTO);

        $discussion = ChangeDiscussionStatusAction::new()->execute($createDiscussionDTO, DiscussionStatusEnum::failed->value);

        Notification::send(
            $createDiscussionDTO->discussion->getOtherUsers($createDiscussionDTO->user), 
            new DiscussionStatusChangedNotification($discussion)
        );

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
        if (is_null($counsellor)) return [];

        $query = Discussion::query();

        $query->where(function ($query) use ($counsellor, $getDiscussionsDTO) {

            if ($getDiscussionsDTO->name)
                $query->where('name', 'LIKE', "%{$getDiscussionsDTO->name}%");
    
            if ($getDiscussionsDTO->for)
                $query->whereFor($getDiscussionsDTO->for);
            
            $query->whereAddedby($counsellor);
        });

        $query->orWhere(function ($query) use ($counsellor, $getDiscussionsDTO) {

            if ($getDiscussionsDTO->name)
                $query->where('name', 'LIKE', "%{$getDiscussionsDTO->name}%");
    
            if ($getDiscussionsDTO->for)
                $query->whereFor($getDiscussionsDTO->for);

            $query->whereCounsellor($counsellor);
        });

        return DiscussionMiniResource::collection($query->paginate(PaginationEnum::pagination->value));
    }

    public function getDiscussionCounsellors(GetDiscussionsDTO $getDiscussionsDTO)
    {
        if (is_null($getDiscussionsDTO->discussion)) return [];

        $query = Counsellor::query();

        $query->whereDiscussion($getDiscussionsDTO->discussion);

        if ($getDiscussionsDTO->name)
            $query->whereName($getDiscussionsDTO->name);

        return CounsellorMiniResource::collection($query->paginate(PaginationEnum::pagination->value));
    }
}