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
    }
    
    private function clearPaymentData()
    {
        $dataKeys = ['per' => '', 'amount' => 0, 'inPersonAmount' => 0, 'currency' => '',];

        foreach ($dataKeys as $key => $value) {
            $this->data['payment_data'][$key] = $value;
        }
    }
    
    private function setValueOnPaymentData(
        String $dataKey,
        GroupTherapyDTO $groupTherapyDTO,
        String|null $objectKey = null
    ){
        $objectKey = $objectKey ?: $dataKey;

        if (
            (
                array_key_exists($dataKey, $this->data['payment_data']) && 
                $this->data['payment_data'][$dataKey] !== $groupTherapyDTO->$dataKey
            ) ||
            !is_null($groupTherapyDTO->$dataKey)
        )
            $this->data['payment_data'][$dataKey] = $groupTherapyDTO->$objectKey;
    }
    
    private function setValueOnData(
        String $dataKey,
        GroupTherapyDTO $groupTherapyDTO,
        String|null $objectKey = null
    ) {
        $objectKey = $objectKey ?: $dataKey;

        if (
            !is_null($groupTherapyDTO->$objectKey) &&
            $groupTherapyDTO->$objectKey !== $groupTherapyDTO->groupTherapy->$dataKey
        )        
            $this->data[$dataKey] = $groupTherapyDTO->$objectKey;
    }
}