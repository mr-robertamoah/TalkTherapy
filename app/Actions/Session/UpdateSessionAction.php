<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionTypeEnum;
use Carbon\Carbon;

class UpdateSessionAction extends Action
{
    private array $data = [];

    public function execute(CreateSessionDTO $createSessionDTO)
    {
        $this->setData($createSessionDTO);

        $createSessionDTO->session->update($this->data);

        if (is_array($createSessionDTO->cases)) {

            $createSessionDTO->session->cases()->detach();
            $createSessionDTO->session->cases()->attach($createSessionDTO->cases);
        }

        if (is_array($createSessionDTO->topics)) {

            $createSessionDTO->session->topics()->detach();
            $createSessionDTO->session->topics()->attach($createSessionDTO->topics);
        }

        // TODO dispatch update event

        return $createSessionDTO->session->refresh();
    }

    private function setData(CreateSessionDTO $createSessionDTO)
    {
        $this->setValueOnData('about', $createSessionDTO);
        $this->setValueOnData('landmark', $createSessionDTO);
        $this->setValueOnData('longitude', $createSessionDTO);
        $this->setValueOnData('latitude', $createSessionDTO);
        $this->setValueOnData('payment_type', $createSessionDTO, 'paymentType');
        $this->setValueOnData('type', $createSessionDTO);
        $this->setValueOnData('name', $createSessionDTO);
        $this->setValueOnData('start_time', $createSessionDTO, 'startTime');
        $this->setValueOnData('end_time', $createSessionDTO, 'endTime');

        // SCRUM-443: an online session has no place -- switching to online clears it (it used to keep the old pin).
        if (($this->data['type'] ?? $createSessionDTO->session->type) !== SessionTypeEnum::in_person->value) {
            $this->data['landmark'] = null;
            $this->data['longitude'] = null;
            $this->data['latitude'] = null;
        }
    }

    private function setValueOnData(
        string $dataKey,
        CreateSessionDTO $createSessionDTO,
        ?string $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            ! is_null($createSessionDTO->$objectKey) &&
            $createSessionDTO->$objectKey !== $createSessionDTO->session->$dataKey
        ) {
            if (in_array($dataKey, ['start_time', 'end_time'])) {
                return $this->data[$dataKey] = (new Carbon($createSessionDTO->$objectKey))->utc();
            }

            $this->data[$dataKey] = $createSessionDTO->$objectKey;
        }
    }
}
