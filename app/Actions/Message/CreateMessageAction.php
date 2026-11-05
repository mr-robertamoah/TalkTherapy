<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\DTOs\FileUploadDTO;
use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use App\Services\FileService;

class CreateMessageAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO): Message
    {
        $message = $createMessageDTO->from->sentMessages()->create([
            'content' => $createMessageDTO->content,
            'message_id' => $createMessageDTO->reply?->id,
            'therapy_topic_id' => $createMessageDTO->therapyTopic?->id,
            'confidential' => $createMessageDTO->confidential,
            'type' => $createMessageDTO->type ?? MessageTypeEnum::normal->value,
            'status' => MessageStatusEnum::sent->value,
        ]);

        $message->for()->associate($createMessageDTO->for);
        $message->to()->associate($createMessageDTO->to);
        $message->sender_anonymous = $this->isMasked($createMessageDTO, $createMessageDTO->from);
        $message->recipient_anonymous = $this->isMasked($createMessageDTO, $createMessageDTO->to);
        $message->save();

        if (! $createMessageDTO->files) {
            return $message;
        }

        $fileService = FileService::new();
        $files = [];

        foreach ($createMessageDTO->files as $uploadedFile) {
            $fileData = $fileService->uploadFile(
                FileUploadDTO::new()->fromArray([
                    'file' => $uploadedFile,
                    'path' => 'messages',
                    // SCRUM-336: private, never the public disk -- see config/filesystems.php.
                    'disk' => 'message_attachments',
                ])
            );

            $files[] = $fileService->saveFile($createMessageDTO->user, $fileData);
        }

        $message->files()->attach(array_map(fn ($f) => $f->id, $files));

        return $message->refresh();
    }

    // SCRUM-73 (the user's decision): whether a group message's sender (or recipient) is masked right
    // now -- the same rule MessageResource uses (hidesIdentityOf()). Stored, so the message stays
    // anonymous after they leave and rejoin as named. A counsellor profile is never masked.
    private function isMasked(CreateMessageDTO $createMessageDTO, mixed $person): bool
    {
        $for = $createMessageDTO->for;

        return $person instanceof User
            && $for instanceof Session
            && $for->for instanceof GroupTherapy
            && $for->for->hidesIdentityOf($person);
    }
}
