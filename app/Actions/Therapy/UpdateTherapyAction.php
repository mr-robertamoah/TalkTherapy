<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;

class UpdateTherapyAction extends Action
{
    private array $data = [];

    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        $this->setData($createTherapyDTO);

        $createTherapyDTO->therapy->update($this->data);

        if (is_array($createTherapyDTO->cases)) {

            $createTherapyDTO->therapy->cases()->detach();
            $createTherapyDTO->therapy->cases()->attach($createTherapyDTO->cases);
        }

        // TODO dispatch update event

        return $createTherapyDTO->therapy->refresh();
    }

    private function setData(CreateTherapyDTO $createTherapyDTO)
    {
        $this->setValueOnData('public', $createTherapyDTO);
        $this->setValueOnData('payment_type', $createTherapyDTO, 'paymentType');
        $this->setValueOnData('session_type', $createTherapyDTO, 'sessionType');
        $this->setValueOnData('allow_in_person', $createTherapyDTO, 'allowInPerson');
        $this->setValueOnData('name', $createTherapyDTO);
        $this->setValueOnData('anonymous', $createTherapyDTO);
        $this->setValueOnData('max_sessions', $createTherapyDTO, 'maxSessions');
        $this->setValueOnData('background_story', $createTherapyDTO, 'backgroundStory');

        $this->data['payment_data'] = $createTherapyDTO->therapy->payment_data;

        if (
            array_key_exists('payment_type', $this->data) &&
            $this->data['payment_type'] == TherapyPaymentTypeEnum::free->value
        ) {
            $this->data['payment_data'] = null;

            return;
        }

        if (is_null($this->data['payment_data'])) {
            $this->data['payment_data'] = [];
            $this->clearPaymentData();
        }

        $this->setValueOnPaymentData('per', $createTherapyDTO);
        $this->setValueOnPaymentData('amount', $createTherapyDTO);
        $this->setValueOnPaymentData('currency', $createTherapyDTO);
        $this->setValueOnPaymentData('inPersonAmount', $createTherapyDTO);
    }

    private function clearPaymentData()
    {
        $dataKeys = ['per' => '', 'amount' => 0, 'inPersonAmount' => 0, 'currency' => ''];

        foreach ($dataKeys as $key => $value) {
            $this->data['payment_data'][$key] = $value;
        }
    }

    // SCRUM-140: omitted (null) must mean "leave unchanged", matching setValueOnData()'s
    // semantics for scalar columns -- the previous array_key_exists(...) && ... !== null branch
    // wrote null over an already-set persisted value whenever a partial update omitted this
    // field, silently nulling out the whole payment_data JSON column on any partial edit to a
    // PAID therapy.
    private function setValueOnPaymentData(string $dataKey, CreateTherapyDTO $createTherapyDTO)
    {
        if (! is_null($createTherapyDTO->$dataKey)) {
            $this->data['payment_data'][$dataKey] = $createTherapyDTO->$dataKey;
        }
    }

    private function setValueOnData(
        string $dataKey,
        CreateTherapyDTO $createTherapyDTO,
        ?string $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            ! is_null($createTherapyDTO->$objectKey) &&
            $createTherapyDTO->$objectKey !== $createTherapyDTO->therapy->$dataKey
        ) {
            $this->data[$dataKey] = $createTherapyDTO->$objectKey;
        }
    }
}
