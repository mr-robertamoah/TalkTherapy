<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\DTOs\FileUploadDTO;
use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use App\Models\Message;
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
        $message->save();

        if (!$createMessageDTO->files)
            return $message;

        $fileService = FileService::new();
        $files = [];

        foreach ($createMessageDTO->files as $uploadedFile) {
            $fileData = $fileService->uploadFile(
                FileUploadDTO::new()->fromArray([
                    'file' => $uploadedFile,
                    'path' => 'messages'
                ])
            );

            $files[] = $fileService->saveFile($createMessageDTO->user, $fileData);
        }

        $message->files()->attach(array_map(fn($f) => $f->id, $files));

        return $message->refresh();
    }
}