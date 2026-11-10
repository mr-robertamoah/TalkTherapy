<?php

namespace App\Actions\HowToStep;

use App\Actions\Action;
use App\DTOs\CreateHowToStepDTO;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteHowToStepAction extends Action
{
    public function execute(CreateHowToStepDTO $createHowToStepDTO)
    {
        $file = $createHowToStepDTO->howToStep->file;

        // SCRUM-194: the pivot row goes with the step. Security review: the stored file is deleted only
        // once that is committed (UpdateHowToAction runs this inside a transaction), so a rollback never
        // leaves a step pointing at a deleted file; a failed storage delete is logged.
        $createHowToStepDTO->howToStep->stepFile()->detach();
        $createHowToStepDTO->howToStep->delete();

        DB::afterCommit(fn () => $this->deleteStoredFile($file));
    }

    private function deleteStoredFile(?File $file): void
    {
        if ($file && ! FileService::new()->deleteFile($file)) {
            Log::warning('A deleted how-to step\'s file could not be removed from storage.', ['file_id' => $file->id]);
        }
    }
}
