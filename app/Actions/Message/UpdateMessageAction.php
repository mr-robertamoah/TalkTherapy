<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\DTOs\FileUploadDTO;
use App\Models\File;
use App\Models\Message;
use App\Services\FileService;

class UpdateMessageAction extends Action
{
    private array $data = [];

    public function execute(CreateMessageDTO $createMessageDTO): Message
    {
        $this->setData($createMessageDTO);

        $createMessageDTO->message->update($this->data);

        $fileService = FileService::new();

        if ($createMessageDTO->files) {
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

            $createMessageDTO->message->files()->attach(array_map(fn ($f) => $f->id, $files));
        }
        
        if ($createMessageDTO->deletedFiles) {
            $files = [];

            foreach ($createMessageDTO->deletedFiles as $fileId) {
                $fileData = $fileService->deleteFile(File::find($fileId));

                $files[] = $fileId;
            }

            $createMessageDTO->message->files()->detach($files);
        }

        return $createMessageDTO->message->refresh();
    }

    private function setData(CreateMessageDTO $createMessageDTO)
    {
        $this->setValueOnData('content', $createMessageDTO);
        $this->setValueOnData('confidential', $createMessageDTO);
        $this->data['message_id'] = $createMessageDTO->reply
            ? $createMessageDTO->reply?->id
            : $createMessageDTO->message->message_id;
        $this->data['therapy_topic_id'] = $createMessageDTO->therapyTopic
            ? $createMessageDTO->therapyTopic?->id
            : $createMessageDTO->message->therapy_topic_id;
    }
    
    private function setValueOnData(
        String $dataKey,
        CreateMessageDTO $createMessageDTO,
        String|null $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            !is_null($createMessageDTO->$objectKey) &&
            $createMessageDTO->$objectKey !== $createMessageDTO->message->$dataKey
        )        
            $this->data[$dataKey] = $createMessageDTO->$objectKey;
    }
}