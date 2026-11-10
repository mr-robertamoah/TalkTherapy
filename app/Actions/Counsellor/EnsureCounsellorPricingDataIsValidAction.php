<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CounsellorPricingDTO;
use App\Exceptions\CounsellorException;

class EnsureCounsellorPricingDataIsValidAction extends Action
{
    // Defense-in-depth on top of the FormRequest's per-row validation -- cross-row consistency
    // the request rules alone don't fully capture. A counsellor is in exactly one of two modes:
    // a single flat row (therapy_type/session_type/per all null), or N override rows, each fully
    // specifying all three scope dimensions -- never a partial row, never both modes at once, and
    // never two override rows covering the exact same scope.
    public function execute(CounsellorPricingDTO $dto): void
    {
        if (empty($dto->pricings)) {
            throw new CounsellorException('At least one pricing entry is required.', 422);
        }

        $flatEntries = array_filter($dto->pricings, fn (array $entry) => $this->isUnscoped($entry));
        $overrideEntries = array_filter($dto->pricings, fn (array $entry) => ! $this->isUnscoped($entry));

        if ($flatEntries && $overrideEntries) {
            throw new CounsellorException('Pricing cannot mix a flat rate with scoped overrides.', 422);
        }

        if ($flatEntries && count($dto->pricings) > 1) {
            throw new CounsellorException('Only one flat rate is allowed at a time.', 422);
        }

        $seenScopes = [];

        foreach ($overrideEntries as $entry) {
            if (! $this->isFullyScoped($entry)) {
                throw new CounsellorException('Each pricing override must specify a therapy type, session type, and per.', 422);
            }

            $scope = implode('|', [$entry['therapyType'], $entry['sessionType'], $entry['per']]);

            if (in_array($scope, $seenScopes)) {
                throw new CounsellorException('Pricing overrides cannot repeat the same therapy type, session type, and per combination.', 422);
            }

            $seenScopes[] = $scope;
        }

        foreach ($dto->pricings as $entry) {
            if (is_null($entry['amount'] ?? null) || is_null($entry['currency'] ?? null)) {
                throw new CounsellorException('Every pricing entry requires both an amount and a currency.', 422);
            }
        }
    }

    private function isUnscoped(array $entry): bool
    {
        return is_null($entry['therapyType'] ?? null) && is_null($entry['sessionType'] ?? null) && is_null($entry['per'] ?? null);
    }

    private function isFullyScoped(array $entry): bool
    {
        return ! is_null($entry['therapyType'] ?? null) && ! is_null($entry['sessionType'] ?? null) && ! is_null($entry['per'] ?? null);
    }
}
