<?php

namespace App\Services;

use App\Actions\Setting\UpdateSettingAction;
use App\DTOs\SettingDTO;
use App\Enums\SettingsEnum;
use App\Models\PlatformSetting;

// TT-7.6b/SCRUM-226: the platform's one generic settings mechanism -- deliberately not
// payout-specific, since the platform fee and the minimum-payout-threshold are required to
// share it rather than each getting their own bespoke config path. get() reads the DB row for a
// key, falling back to config('settings.*') (env-backed) only when no row has been set yet, so a
// super admin's change always takes precedence over the deploy-time default.
class SettingsService extends Service
{
    public function get(SettingsEnum $key, mixed $default = null): mixed
    {
        $value = PlatformSetting::query()->where('key', $key->value)->value('value');

        // An explicitly-stored empty string is treated the same as "never set" and falls through
        // to $default -- deliberate, not just `??`, since a plain null-coalesce would let an
        // empty string silently reach a money-facing caller (e.g. getPlatformFeePercentage()
        // casting '' to 0.0%, a materially different outcome than "no override, use the
        // deploy-time default"). Revisit if a future setting's valid value could legitimately be
        // an empty string.
        return $value === null || $value === '' ? $default : $value;
    }

    public function getPlatformFeePercentage(): float
    {
        return (float) $this->get(SettingsEnum::platformFeePercentage, config('settings.platform_fee_percentage'));
    }

    // Minor units (pesewas/cents), same convention as transactions.amount.
    public function getMinimumPayoutAmount(string $currency): int
    {
        $configured = json_decode($this->get(SettingsEnum::minimumPayoutAmount) ?? '', true) ?: [];

        return (int) ($configured[strtoupper($currency)] ?? config("settings.minimum_payout_amount.{$currency}", 0));
    }

    public function update(SettingDTO $dto): PlatformSetting
    {
        return UpdateSettingAction::new()->execute($dto);
    }
}
