<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use InvalidArgumentException;

// Reviewer finding (TT-7.3b-b/SCRUM-233): the ONE place the platform-fee percentage actually
// multiplies against a money amount, whatever the base represents (a personal payment the fee is
// taken from, or a listed rate an organisation pays the fee on top of). This action only owns the
// basis-points conversion and the multiplication.
//
// TT-7.10a/SCRUM-467: only PlatformFeePolicy calls this (an arch test enforces it), passing the percentage it read
// once -- whether a fee applies at all (a fee-free period) is the policy's decision, never this action's.
class ComputePlatformFeeAction extends Action
{
    public function execute(int $baseAmount, float $percentage): int
    {
        // Basis points (percentage * 100), not the raw float percentage, are what actually
        // multiply the money -- SettingsService allows an admin to set a fractional fee (e.g.
        // 12.5%), and floating-point arithmetic directly on a currency amount can drift by a
        // minor unit. Converting the percentage to an integer once, up front, keeps every
        // subsequent operation in the same integer/minor-unit space as the rest of this codebase's
        // money math.
        $feeBasisPoints = (int) round($percentage * 100);

        // Defensive overflow guard (security-engineer finding), mirroring
        // ComputeCounsellorCompensationShareAction's identical guard on the same class of
        // multiplication -- $feeBasisPoints is platform-controlled, not org/counsellor-supplied,
        // but $baseAmount isn't bounded here, and PHP has no integer-overflow exception, only a
        // silent promotion to a wrong float.
        if ($baseAmount > 0 && $feeBasisPoints > intdiv(PHP_INT_MAX, $baseAmount)) {
            throw new InvalidArgumentException('The base amount is too large to compute a platform fee safely.');
        }

        return intdiv($baseAmount * $feeBasisPoints, 10000);
    }
}
