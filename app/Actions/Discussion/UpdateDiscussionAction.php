<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use Carbon\Carbon;

class UpdateDiscussionAction extends Action
{
    private array $data = [];

    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        $this->setData($createDiscussionDTO);

        $createDiscussionDTO->discussion->update($this->data);

        if ($createDiscussionDTO->for) {
            
            $createDiscussionDTO->discussion->for()->disassociate();
            $createDiscussionDTO->discussion->for()->associate($createDiscussionDTO->for);
        }

        return $createDiscussionDTO->discussion->refresh();
    }

    private function setData(CreateDiscussionDTO $createDiscussionDTO)
    {
        $this->setValueOnData('name', $createDiscussionDTO);
        $this->setValueOnData('description', $createDiscussionDTO);
        $this->setValueOnData('start_time', $createDiscussionDTO, 'startTime');
        $this->setValueOnData('end_time', $createDiscussionDTO, 'endTime');

        if ($createDiscussionDTO->session)
            $this->data['session_id'] = $createDiscussionDTO->session->id;
    }
    
    private function setValueOnData(
        String $dataKey,
        CreateDiscussionDTO $createDiscussionDTO,
        String|null $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            !is_null($createDiscussionDTO->$objectKey) &&
            $createDiscussionDTO->$objectKey !== $createDiscussionDTO->discussion->$dataKey
        ) {
            if (in_array($dataKey, ['start_time', 'end_time'])) return $this->data[$dataKey] = (new Carbon($createDiscussionDTO->$objectKey))->utc();
            
            $this->data[$dataKey] = $createDiscussionDTO->$objectKey;
        }
    }
}