<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateTherapyTopicDTO;

class UpdateTherapyTopicAction extends Action
{
    private array $data = [];

    public function execute(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        $this->setData($createTherapyTopicDTO);

        $createTherapyTopicDTO->therapyTopic->update($this->data);

        if ($createTherapyTopicDTO->sessions && count($createTherapyTopicDTO->sessions)) {
            
            $createTherapyTopicDTO->therapyTopic->sessions()->detach();
            $createTherapyTopicDTO->therapyTopic->sessions()->attach($createTherapyTopicDTO->sessions);
        }

        // TODO dispatch update event

        return $createTherapyTopicDTO->therapyTopic->refresh();
    }

    private function setData(CreateTherapyTopicDTO $createTherapyTopicDTO)
    {
        $this->setValueOnData('description', $createTherapyTopicDTO);
        $this->setValueOnData('name', $createTherapyTopicDTO);
    }
    
    private function setValueOnData(
        String $dataKey,
        CreateTherapyTopicDTO $createTherapyTopicDTO,
        String|null $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            !is_null($createTherapyTopicDTO->$objectKey) &&
            $createTherapyTopicDTO->$objectKey !== $createTherapyTopicDTO->therapyTopic->$dataKey
        )        
            $this->data[$dataKey] = $createTherapyTopicDTO->$objectKey;
    }
}