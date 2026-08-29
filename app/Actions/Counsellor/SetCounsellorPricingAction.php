<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CounsellorPricingDTO;
use App\Models\CounsellorPricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SetCounsellorPricingAction extends Action
{
    // Full delete-and-reinsert per save, not incremental upsert -- avoids ever persisting an
    // invalid in-between state (e.g. a flat row alongside a stale override row from a prior save)
    // without needing DB-level uniqueness tricks. Safe here specifically because this is a
    // single-writer, low-contention field with no TOCTOU concern like
    // organization_counsellor_compensations had -- and no history to preserve (SCRUM-154).
    public function execute(CounsellorPricingDTO $dto): Collection
    {
        return DB::transaction(function () use ($dto) {
            $dto->counsellor->pricings()->delete();

            $rows = array_map(fn (array $entry) => [
                'counsellor_id' => $dto->counsellor->id,
                'therapy_type' => $entry['therapyType'] ?? null,
                'session_type' => $entry['sessionType'] ?? null,
                'per' => $entry['per'] ?? null,
                'amount' => $entry['amount'],
                'currency' => $entry['currency'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $dto->pricings);

            CounsellorPricing::query()->insert($rows);

            return $dto->counsellor->pricings()->get();
        });
    }
}
