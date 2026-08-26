<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class TransactionDTO extends BaseDTO
{
    public ?User $user = null;

    public Therapy|GroupTherapy|Session|null $for = null;

    public ?Transaction $transaction = null;

    public ?string $reference = null;

    public ?string $signature = null;

    public ?array $payload = null;

    public ?string $rawBody = null;

    public ?string $callbackUrl = null;
}
