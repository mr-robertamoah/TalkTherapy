<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;

class UpdateGroupTherapyAction extends Action
{
    private array $data = [];

    public function execute(GroupTherapyDTO $groupTherapyDTO)
    {
        $this->setData($groupTherapyDTO);

        $groupTherapyDTO->groupTherapy->update($this->data);

        if (is_array($groupTherapyDTO->cases)) {

            $groupTherapyDTO->groupTherapy->cases()->detach();
            $groupTherapyDTO->groupTherapy->cases()->attach($groupTherapyDTO->cases);
        }

        // TODO dispatch update event

        return $groupTherapyDTO->groupTherapy->refresh();
    }

    private function setData(GroupTherapyDTO $groupTherapyDTO)
    {
        $this->setValueOnData('public', $groupTherapyDTO);
        $this->setValueOnData('payment_type', $groupTherapyDTO, 'paymentType');
        $this->setValueOnData('session_type', $groupTherapyDTO, 'sessionType');
        $this->setValueOnData('allow_in_person', $groupTherapyDTO, 'allowInPerson');
        $this->setValueOnData('name', $groupTherapyDTO);
        $this->setValueOnData('anonymous', $groupTherapyDTO);
        $this->setValueOnData('max_sessions', $groupTherapyDTO, 'maxSessions');
        $this->setValueOnData('max_counsellors', $groupTherapyDTO, 'maxCounsellors');
        $this->setValueOnData('max_users', $groupTherapyDTO, 'maxUsers');
        $this->setValueOnData('allow_anyone', $groupTherapyDTO, 'allowAnyone');
        $this->setValueOnData('about', $groupTherapyDTO);

        $this->data['payment_data'] = $groupTherapyDTO->groupTherapy->payment_data;

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

        $this->setValueOnPaymentData('per', $groupTherapyDTO);
        $this->setValueOnPaymentData('amount', $groupTherapyDTO);
        $this->setValueOnPaymentData('currency', $groupTherapyDTO);
        $this->setValueOnPaymentData('inPersonAmount', $groupTherapyDTO);
        $this->setValueOnPaymentData('shareEqually', $groupTherapyDTO);
        $this->setValueOnPaymentData('sharePercentage', $groupTherapyDTO);
        $this->setValueOnPaymentData('strictPaymentGate', $groupTherapyDTO);
        $this->setValueOnPaymentData('allowFreeHistoricalAccess', $groupTherapyDTO);

        // TT-7.5b-b1/SCRUM-265: setValueOnPaymentData() writes the DTO's raw value verbatim
        // (needed for numeric/string fields like amount/currency above) -- force-cast to real
        // bools here, mirroring UpdateTherapyAction's identical symmetry fix for its own
        // strictPaymentGate.
        foreach (['strictPaymentGate', 'allowFreeHistoricalAccess'] as $booleanKey) {
            if (array_key_exists($booleanKey, $this->data['payment_data'])) {
                $this->data['payment_data'][$booleanKey] = (bool) $this->data['payment_data'][$booleanKey];
            }
        }
    }

    private function clearPaymentData()
    {
        // TT-7.5b-b1/SCRUM-265: strictPaymentGate/allowFreeHistoricalAccess default here too
        // (mirrors UpdateTherapyAction's own SCRUM-217 precedent), so a group whose payment_data
        // was previously null (e.g. switched from FREE back to PAID) starts trust-based with the
        // sibling setting at its normal default, rather than with either key simply absent.
        $dataKeys = [
            'per' => '',
            'amount' => 0,
            'inPersonAmount' => 0,
            'currency' => '',
            'strictPaymentGate' => false,
            'allowFreeHistoricalAccess' => true,
        ];

        foreach ($dataKeys as $key => $value) {
            $this->data['payment_data'][$key] = $value;
        }
    }

    // SCRUM-140: omitted (null) must mean "leave unchanged", matching setValueOnData()'s
    // semantics for scalar columns -- the previous array_key_exists(...) && ... !== null branch
    // wrote null over an already-set persisted value whenever a partial update omitted this
    // field, silently nulling out the whole payment_data JSON column on any partial edit to a
    // PAID group therapy.
    private function setValueOnPaymentData(
        string $dataKey,
        GroupTherapyDTO $groupTherapyDTO,
        ?string $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (! is_null($groupTherapyDTO->$objectKey)) {
            $this->data['payment_data'][$dataKey] = $groupTherapyDTO->$objectKey;
        }
    }

    private function setValueOnData(
        string $dataKey,
        GroupTherapyDTO $groupTherapyDTO,
        ?string $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            ! is_null($groupTherapyDTO->$objectKey) &&
            $groupTherapyDTO->$objectKey !== $groupTherapyDTO->groupTherapy->$dataKey
        ) {
            $this->data[$dataKey] = $groupTherapyDTO->$objectKey;
        }
    }
}
