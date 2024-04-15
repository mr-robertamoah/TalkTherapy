<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Message;

class DeleteMessageForMeAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        Message::withoutTimestamps(function () use ($createMessageDTO) {

            $createMessageDTO->message->update([
                'deleted_for' => $this->setDeleteFor($createMessageDTO)
            ]);
        });

        return $createMessageDTO->message->refresh();
    }

    private function setDeleteFor(CreateMessageDTO $createMessageDTO): string|null
    {
        $deleteFor = "";
        $ids = [];

        if ($createMessageDTO->message->deleted_for)
            $ids = explode(',', $createMessageDTO->message->deleted_for);

        if (in_array("{$createMessageDTO->user->id}", $ids))
            throw new MessageException("You have already delete the message for yourself.", 422);

        if (count($ids))
            $deleteFor = implode(',', $ids) . ",";

        $deleteFor = $deleteFor . "{$createMessageDTO->user->id}";
        
        return $deleteFor;
    }
}