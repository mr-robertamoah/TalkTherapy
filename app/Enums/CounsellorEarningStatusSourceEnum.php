<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// Mirrors TransactionStatusSourceEnum's role, but for the counsellor-earnings lifecycle rather
// than the payment-gateway one. Only `generation` is produced by TT-7.6b; TT-7.6c adds the
// payout-side cases (claimed/succeeded/failed) when it starts writing to this history table.
enum CounsellorEarningStatusSourceEnum: string
{
    use EnumTrait;

    case generation = 'GENERATION';
}
