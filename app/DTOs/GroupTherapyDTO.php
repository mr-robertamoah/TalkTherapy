<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GroupTherapyDTO extends BaseDTO
{
    public ?Counsellor $counsellor = null;

    public ?User $user = null;

    // Nullable, not defaulted (SCRUM-127) -- CreateGroupTherapyRequest already allows this to be
    // omitted (`'nullable'`), and SendTherapyAssistanceRequestAction already no-ops on a null
    // `to`; the crash was purely this property's non-nullable declaration, not missing handling.
    public ?array $counsellorIds = null;

    public ?GroupTherapy $groupTherapy = null;

    public ?string $name = null;

    public ?bool $isEmergency = null;

    public ?array $cases = null;

    public ?string $about = null;

    public ?string $per = null;

    public ?string $currency = null;

    public ?float $inPersonAmount = null;

    public ?float $amount = null;

    // Nullable, not defaulted, so an update omitting one of these doesn't crash on assignment
    // (SCRUM-127) -- UpdateGroupTherapyAction's setValueOnData()/setValueOnPaymentData() already
    // skip null values; creation is unaffected since CreateGroupTherapyRequest requires
    // public/allowInPerson/anonymous/allowAnyone (shareEqually has no such rule -- see below).
    public ?bool $public = null;

    public ?bool $allowInPerson = null;

    public ?bool $anonymous = null;

    public ?bool $allowAnyone = null;

    public ?string $sessionType = null;

    public ?string $paymentType = null;

    public ?int $maxSessions = null;

    public ?int $sharePercentage = null;

    public ?int $maxCounsellors = null;

    public ?int $maxUsers = null;

    // Unlike the other booleans above, CreateGroupTherapyRequest has no validation rule for this
    // at all (accepted but unvalidated), so it could already be omitted on creation, not just
    // update -- same crash, same fix (SCRUM-127).
    public ?bool $shareEqually = null;

    // TT-7.5b-b1/SCRUM-265: same payment_data key name TT-7.5a already established for individual
    // Therapy (CreateTherapyDTO::$strictPaymentGate). Authorization for CHANGING this once a
    // GroupTherapy already exists is EnsureCanSetGroupTherapyPaymentGateAction's job (TT-7.5b-b0),
    // not this DTO's.
    public ?bool $strictPaymentGate = null;

    // TT-7.5b-b1/SCRUM-265: sibling setting (default true, enforced at write time in
    // CreateGroupTherapyAction/UpdateGroupTherapyAction, not here) -- controls whether a
    // late-joining member can see content that predates their own join, once strictPaymentGate is
    // on. Enforcement is TT-7.5b-b3's job; this ticket only persists the value.
    public ?bool $allowFreeHistoricalAccess = null;
}
