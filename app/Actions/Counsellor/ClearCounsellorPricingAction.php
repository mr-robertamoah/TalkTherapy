<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CounsellorPricingDTO;

class ClearCounsellorPricingAction extends Action
{
    // SCRUM-155 (TT-7.2c): discovered while building the UI -- SetCounsellorPricingAction
    // requires at least one pricing entry (a counsellor is always in "flat" or "override" mode),
    // so there was no way to represent "no pricing listed at all" once a counsellor had set one.
    public function execute(CounsellorPricingDTO $dto): void
    {
        $dto->counsellor->pricings()->delete();
    }
}
