<?php

namespace App\Actions\HowToStep;

use App\Actions\Action;
use App\DTOs\CreateHowToStepDTO;
use App\Services\FileService;

class DeleteHowToStepAction extends Action
{
    public function execute(CreateHowToStepDTO $createHowToStepDTO)
    {
        FileService::new()->deleteFile($createHowToStepDTO->howToStep->file);
        $createHowToStepDTO->howToStep->delete();
    }
}