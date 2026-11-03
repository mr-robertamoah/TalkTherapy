<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use Illuminate\Support\Collection;

// SCRUM-141: before SCRUM-140, a partial update to a PAID therapy or group therapy that left out the
// payment fields wiped payment_data while payment_type stayed PAID. Such a row can't be edited at all
// now: EnsureTherapyDataIsValidAction refuses it until someone supplies the payment settings again.
// This finds them, read-only, so the owners can be contacted (payments:audit-paid-therapies).
// Checked in PHP rather than with JSON_EXTRACT, so it runs the same on MySQL and on the SQLite tests.
class FindPaidTherapiesMissingPaymentDataAction extends Action
{
    // The three EnsureTherapyDataIsValidAction falls back to payment_data for.
    public const REQUIRED_KEYS = ['amount', 'currency', 'per'];

    /**
     * @return Collection<int, array{type: string, id: int, name: string, owner: string, missing: list<string>}>
     */
    public function execute(): Collection
    {
        return collect([Therapy::class, GroupTherapy::class])->flatMap(fn (string $model) => $model::query()
            ->where('payment_type', TherapyPaymentTypeEnum::paid->value)
            ->with('addedby')
            ->lazyById()
            ->map(fn ($therapy) => $this->problem($therapy))
            ->filter()
            ->values()
            ->all());
    }

    private function problem(Therapy|GroupTherapy $therapy): ?array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_KEYS,
            fn (string $key) => blank(data_get($therapy->payment_data, $key)),
        ));

        if (! $missing) {
            return null;
        }

        return [
            'type' => class_basename($therapy),
            'id' => $therapy->id,
            'name' => (string) $therapy->name,
            'owner' => $therapy->addedby
                ? class_basename($therapy->addedby).' #'.$therapy->addedby->getKey()
                : class_basename((string) $therapy->addedby_type).' #'.$therapy->addedby_id.' (deleted)',
            'missing' => $missing,
        ];
    }
}
