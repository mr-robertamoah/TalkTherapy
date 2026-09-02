<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CreateTherapyDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Counsellor $counsellor = null;

    public ?Therapy $therapy = null;

    public ?string $name = null;

    public ?bool $isEmergency = null;

    public ?array $cases = null;

    public ?string $backgroundStory = null;

    public ?string $per = null;

    public ?string $currency = null;

    public ?float $inPersonAmount = null;

    public ?float $amount = null;

    // Nullable, not defaulted, so an update omitting one of these doesn't crash on assignment
    // (SCRUM-127) -- UpdateTherapyAction's setValueOnData() already skips null values; creation
    // is unaffected since CreateTherapyRequest requires all three.
    public ?bool $public = null;

    public ?bool $allowInPerson = null;

    public ?bool $anonymous = null;

    public ?string $sessionType = null;

    public ?string $paymentType = null;

    public ?int $maxSessions = null;

    // SCRUM-217/TT-7.5a: strict payment gate vs. trust-based access, defaulting to trust-based
    // (false) at creation. Null on update means "leave unchanged", matching every other
    // payment_data field's semantics (UpdateTherapyAction::setValueOnPaymentData()).
    public ?bool $strictPaymentGate = null;
}
