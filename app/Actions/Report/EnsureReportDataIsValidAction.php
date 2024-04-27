<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\Counsellor;
use App\Models\User;

class EnsureReportDataIsValidAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO, string $action = 'create')
    {
        $this->validateData($createReportDTO);

        $this->validateDataEntries($createReportDTO);

        if (
            $this->canCreate($createReportDTO) ||
            ($action == 'update' && $this->canUpdate($createReportDTO))
        ) return;

        throw new ReportException("You have not provided enough data to {$action} report.", 422);
    }

    private function canCreate(CreateReportDTO $createReportDTO)
    {
        return $createReportDTO->description && $createReportDTO->reportable;
    }

    private function validateData(CreateReportDTO $createReportDTO)
    {
        if (
            $createReportDTO->reportable->isTherapy &&
            (
                is_null($createReportDTO->data) ||
                !count($createReportDTO->data) ||
                !array_key_exists('userId', $createReportDTO->data) ||
                !array_key_exists('counsellorId', $createReportDTO->data)
            )
        ) throw new ReportException("Please provide the person(s) you are reporting for this therapy.", 422);

        return;
    }

    private function validateDataEntries(CreateReportDTO $createReportDTO)
    {
        if (
            $createReportDTO->reportable->isTherapy &&
            (
                array_key_exists('userId', $createReportDTO->data) &&
                !$createReportDTO->reportable->isUser(User::find($createReportDTO->data['userId']))
            )
        ) throw new ReportException("The user provided is not participating in the therapy ". $createReportDTO->reportable->name . ".", 422);

        if (
            $createReportDTO->reportable->isTherapy &&
            (
                array_key_exists('counsellorId', $createReportDTO->data) &&
                !$createReportDTO->reportable->isCounsellor(Counsellor::find($createReportDTO->data['counsellorId']))
            )
        ) throw new ReportException("The counsellor provided is not participating in the therapy ". $createReportDTO->reportable->name . ".", 422);
        return;
    }

    private function canUpdate(CreateReportDTO $createReportDTO)
    {
        return $createReportDTO->description ||
            $createReportDTO->files ||
            $createReportDTO->deletedFiles ||
            $createReportDTO->reportable;
    }
}