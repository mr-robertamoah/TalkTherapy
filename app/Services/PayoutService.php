<?php

namespace App\Services;

use App\Actions\EnsureIsAdminAction;
use App\Actions\Payout\CreateCounsellorPayoutDestinationAction;
use App\Actions\Payout\EnsureCanOnboardPayoutDestinationAction;
use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\Actions\Payout\TriggerCounsellorPayoutAction;
use App\DTOs\GetCounsellorPayoutOverviewForAdminDTO;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\PayoutDestinationDTO;
use App\DTOs\TriggerPayoutDTO;
use App\Enums\PaginationEnum;
use App\Exceptions\PayoutException;
use App\Http\Resources\AdminCounsellorPayoutResource;
use App\Models\CounsellorPayout;
use App\Models\CounsellorPayoutAccount;

class PayoutService extends Service
{
    public function onboardDestination(PayoutDestinationDTO $dto): CounsellorPayoutAccount
    {
        EnsureCanOnboardPayoutDestinationAction::new()->execute($dto);

        return CreateCounsellorPayoutDestinationAction::new()->execute($dto);
    }

    public function triggerPayout(TriggerPayoutDTO $dto): CounsellorPayout
    {
        return TriggerCounsellorPayoutAction::new()->execute($dto);
    }

    // A hard exception (matching the overview method below), not the silent-empty-result
    // convention CounsellorService::geCounsellorsForAdmin() uses -- this audit listing exposes
    // counsellor names, amounts, references, and failure reasons across EVERY counsellor, more
    // sensitive than that mirrored convention's own profile-field listing (security-engineer
    // finding: a silent empty array fails invisibly under a future refactor, with nothing to
    // catch or alert on if the guard is ever weakened or moved after the query).
    public function getPayoutsForAdmin(GetModelsForAdminDTO $dto)
    {
        EnsureIsAdminAction::new()->execute($dto, 'You must be an administrator to view payout history.');

        return AdminCounsellorPayoutResource::collection(
            CounsellorPayout::query()
                ->with(['counsellor', 'initiatedBy', 'statusHistories'])
                ->latest()
                ->paginate(PaginationEnum::preferencesPagination->value)
        );
    }

    // Unlike the listing above, this targets one specific counsellor's private financial data --
    // an unauthorized caller gets a real 422, not a silently-empty response.
    public function getPayoutOverviewForAdmin(GetCounsellorPayoutOverviewForAdminDTO $dto): array
    {
        EnsureIsAdminAction::new()->execute($dto, "You must be an administrator to view a counsellor's payout overview.");

        if (is_null($dto->counsellor)) {
            throw new PayoutException('Counsellor not found.', 404);
        }

        return GetCounsellorPayoutOverviewAction::new()->execute($dto->counsellor);
    }
}
