<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Enums\DiscussionStatusEnum;

class ChangeDiscussionStatusAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO, string $status)
    {
        $createDiscussionDTO->discussion->update([
            'status' => $status
        ]);
            
        return $createDiscussionDTO->discussion->refresh();
    }
}