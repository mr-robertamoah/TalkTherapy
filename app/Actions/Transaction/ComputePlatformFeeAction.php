<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Services\SettingsService;
use InvalidArgumentException;

// Reviewer finding (TT-7.3b-b/SCRUM-233): the ONE place the platform-fee percentage actually
// multiplies against a money amount -- previously duplicated verbatim between
// GenerateCounsellorEarningsAction (fee deducted from a personal-pay gross amount) and
// ChargeOrganizationForModelAction (fee added on top of an org-compensation share). Both callers
// pass a different base amount; this action only owns the basis-points conversion and the
// multiplication, never what the base represents.
class ComputePlatformFeeAction extends Action
{
    public function execute(int $baseAmount): int
    {
        // Basis points (percentage * 100), not the raw float percentage, are what actually
        // multiply the money -- SettingsService allows an admin to set a fractional fee (e.g.
        // 12.5%), and floating-point arithmetic directly on a currency amount can drift by a
        // minor unit. Converting the percentage to an integer once, up front, keeps every
        // subsequent operation in the same integer/minor-unit space as the rest of this codebase's
        // money math.
        $feeBasisPoints = (int) round(SettingsService::new()->getPlatformFeePercentage() * 100);

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
