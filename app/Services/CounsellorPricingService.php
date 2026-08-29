<?php

namespace App\Services;

use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\Counsellor\EnsureCounsellorPricingDataIsValidAction;
use App\Actions\Counsellor\EnsureUserCanSetCounsellorPricingAction;
use App\Actions\Counsellor\SetCounsellorPricingAction;
use App\DTOs\CounsellorPricingDTO;
use Illuminate\Support\Collection;

class CounsellorPricingService extends Service
{
    public function setPricing(CounsellorPricingDTO $dto): Collection
    {
        EnsureCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanSetCounsellorPricingAction::new()->execute($dto);

        EnsureCounsellorPricingDataIsValidAction::new()->execute($dto);

        return SetCounsellorPricingAction::new()->execute($dto);
    }
}
