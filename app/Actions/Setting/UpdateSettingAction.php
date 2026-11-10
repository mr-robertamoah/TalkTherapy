<?php

namespace App\Actions\Setting;

use App\Actions\Action;
use App\Actions\EnsureIsSuperAdminAction;
use App\DTOs\SettingDTO;
use App\Models\PlatformSetting;

// TT-7.6b/SCRUM-226: only a super admin may change a platform setting (the platform fee and the
// minimum-payout threshold both live here) -- TT-7.6e wires the controller/route that will call
// this once the admin-facing UI exists; nothing consumes it yet.
class UpdateSettingAction extends Action
{
    public function execute(SettingDTO $dto): PlatformSetting
    {
        EnsureIsSuperAdminAction::new()->execute($dto, 'Only a super administrator can change platform settings.');

        return PlatformSetting::query()->updateOrCreate(
            ['key' => $dto->key->value],
            ['value' => $dto->value, 'updated_by_id' => $dto->user->id]
        );
    }
}
