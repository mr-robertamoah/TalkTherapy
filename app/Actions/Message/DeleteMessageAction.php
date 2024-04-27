<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Models\Message;
use App\Services\FileService;

class DeleteMessageAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        Message::withoutTimestamps(function () use ($createMessageDTO) {
            $fileService = FileService::new();
            $files = [];
            
            foreach ($createMessageDTO->post->files as $file) {
                $files[] = $file->id;
                
                $fileService->deleteFile($file);
            }
    
            $createMessageDTO->post->files()->detach($files);
            $createMessageDTO->message->delete();
        });

        return $createMessageDTO->message->refresh();
    }
}