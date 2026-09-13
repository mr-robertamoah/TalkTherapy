<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum RequestStatusEnum: string
{
    use EnumTrait;

    case pending = 'PENDING';
    case rejected = 'REJECTED';
    case accepted = 'ACCEPTED';
    case inconsequencial = 'INCONSEQUENCIAL';
    // TT-4.11c/SCRUM-304: a pending dobChange request auto-closed because an ageVerification
    // request for the same user was approved instead -- deliberately NOT `rejected`, which would
    // misleadingly imply the guardian's submitted dob was judged wrong on its own merits rather
    // than simply superseded by stronger, admin-verified evidence. One-directional: an ordinary
    // dobChange approval never supersedes a pending ageVerification (a self-report is never
    // "verified"). See SupersedePendingDobChangeRequestsAction.
    case superseded = 'SUPERSEDED';
}
