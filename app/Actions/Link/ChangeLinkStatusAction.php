<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Link;

class ChangeLinkStatusAction extends Action
{
    public const CANNOT_REACTIVATE = 'A link that has been used or revoked cannot be switched back on. Create a new one instead.';

    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if ($createLinkDTO->link->state == LinkStateEnum::active->value) {
            $createLinkDTO->link->deactivate();
        } else {
            self::ensureCanReactivate($createLinkDTO->link);

            $createLinkDTO->link->activate();
        }

        return $createLinkDTO->link->refresh();
    }

    // SCRUM-415: links are single use (SCRUM-101) -- switching a used one back on would let it be used
    // again. An organisation join link is never switched back on: one used before used_at existed looks
    // the same as a revoked one, and its admins create a new one instead.
    public static function ensureCanReactivate(Link $link): void
    {
        if ($link->isUsed() || $link->type === LinkTypeEnum::organizationSelfApply->value) {
            throw new LinkException(self::CANNOT_REACTIVATE, 422);
        }
    }
}
