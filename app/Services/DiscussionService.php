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
use App\Actions\Discussion\UpdateDiscussionAction;
use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\EnsureIsCounsellorAction;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\DiscussionStatusEnum;
use App\Enums\StarTypeEnum;
use App\Events\DiscussionUpdatedEvent;
use App\Models\Discussion;
use App\Notifications\DiscussionDeletedNotification;
use App\Notifications\DiscussionStatusChangedNotification;
use App\Notifications\DiscussionUpdatedNotification;
use Illuminate\Support\Facades\Notification;

class DiscussionService extends Service
{
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

        $discussion = UpdateDiscussionAction::new()->execute($createDiscussionDTO);

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
}