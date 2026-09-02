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
        $this->setValueOnPaymentData('strictPaymentGate', $createTherapyDTO);

        // setValueOnPaymentData() writes the DTO's raw value verbatim (needed for numeric/string
        // fields like amount/currency above) -- force-cast to a real bool here for symmetry with
        // Therapy::getStrictPaymentGateAttribute()'s read-side cast.
        if (array_key_exists('strictPaymentGate', $this->data['payment_data'])) {
            $this->data['payment_data']['strictPaymentGate'] = (bool) $this->data['payment_data']['strictPaymentGate'];
        }
    }

    private function clearPaymentData()
    {
        // SCRUM-217/TT-7.5a: strictPaymentGate defaults to false (trust-based) here too, so a
        // therapy whose payment_data was previously null (e.g. switched from FREE back to PAID)
        // starts trust-based rather than with the key simply absent.
        $dataKeys = ['per' => '', 'amount' => 0, 'inPersonAmount' => 0, 'currency' => '', 'strictPaymentGate' => false];

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
