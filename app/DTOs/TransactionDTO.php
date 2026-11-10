<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Organization;
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

    // SCRUM-48 (TT-7.3a): organizationId is the raw, unresolved input -- null means the existing
    // personal-pay flow was requested (the mode switch EnsureOrganizationCanPayForModelAction
    // guards on). organization is the resolved model, separately nullable so that "an
    // organizationId was supplied but doesn't resolve to a real org" is distinguishable, inside
    // that action, from "no organizationId was supplied at all" -- collapsing the two would let an
    // invalid/foreign organizationId silently fall back to being charged personally instead of
    // being rejected.
    public ?int $organizationId = null;

    public ?Organization $organization = null;

    public ?string $reference = null;

    public ?string $signature = null;

    public ?array $payload = null;

    public ?string $rawBody = null;

    public ?string $callbackUrl = null;
}
