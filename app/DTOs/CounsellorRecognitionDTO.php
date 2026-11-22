<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

// TT-7.11d1/SCRUM-473: who is acting on a recognition, and on what -- a month and a counsellor (a super
// administrator's decision), or a recognition and a consent change (its counsellor).
class CounsellorRecognitionDTO extends BaseDTO
{
    public ?User $user = null;

    // 'YYYY-MM'; CounsellorRecognitionService checks it again (CounsellorRecognition::parseMonth()).
    public ?string $month = null;

    public ?int $counsellorId = null;

    public ?int $recognitionId = null;

    // 'withdraw' or 'restore'.
    public ?string $consentAction = null;
}
