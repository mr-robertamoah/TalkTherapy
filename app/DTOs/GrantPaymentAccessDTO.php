<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GrantPaymentAccessDTO extends BaseDTO
{
    public ?User $user = null;

    // Mirrors TransactionDTO::$for's shape. TT-7.5b-b2/SCRUM-266: widened to GroupTherapy --
    // without this, EnsureStrictPaymentGateSatisfiedAction::ensureGrantedOrPaid() throws a
    // TypeError (not a PaymentRequiredException) the first time a GroupTherapy member's
    // successful transaction reaches this DTO, silently failing to persist the grant.
    public Therapy|GroupTherapy|Session|null $for = null;

    public ?Transaction $transaction = null;
}
