<?php

namespace App\DTOs;

use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GrantPaymentAccessDTO extends BaseDTO
{
    public ?User $user = null;

    // Mirrors TransactionDTO::$for's shape, minus GroupTherapy -- TT-7.5b (GroupTherapy gating)
    // is blocked on TT-7.4d and out of scope here.
    public Therapy|Session|null $for = null;

    public ?Transaction $transaction = null;
}
