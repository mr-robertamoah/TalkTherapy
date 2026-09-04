<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;

// TT-7.3b-d/SCRUM-235 (reviewer finding): extracted from a byte-for-byte duplicate between
// ChargeOrganizationForModelAction and GenerateCounsellorEarningsAction -- the ONE place a
// GetPayableAmountAction result becomes the minor-units integer both ComputeCounsellorCompensationShareAction's
// COUNSELLOR_RATE basis and ComputePlatformFeeAction's own basis are computed from.
class ResolveCounsellorListedAmountAction extends Action
{
    public function execute(Therapy|GroupTherapy|Session $for): ?int
    {
        $payable = GetPayableAmountAction::new()->execute($for);

        return isset($payable['amount']) ? (int) round($payable['amount'] * 100) : null;
    }
}
