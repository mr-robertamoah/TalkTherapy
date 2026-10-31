<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Therapy;
use App\Models\User;

class EnsureLinkDataIsValidAction extends Action
{
    // SCRUM-349 (code review): each link type is for exactly one kind of model. The perform actions
    // look `for` up by id in their own table, so a THERAPY_COUNSELLOR link for GroupTherapy #N used to
    // assign a counsellor to the unrelated Therapy #N once GroupTherapy started resolving. Who may
    // create a link for a given target is SCRUM-350.
    private const FOR_CLASSES = [
        'DISCUSSION' => Discussion::class,
        'GUARDIANSHIP' => User::class,
        'GROUP_THERAPY_ADMIN' => GroupTherapy::class,
        'GROUP_THERAPY_PARTICIPANT' => GroupTherapy::class,
        'GROUP_THERAPY_COUNSELLOR' => GroupTherapy::class,
        'THERAPY_COUNSELLOR' => Therapy::class,
        'ORGANIZATION_SELF_APPLY' => Organization::class,
    ];

    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (
            $createLinkDTO->for &&
            $createLinkDTO->type &&
            in_array($createLinkDTO->type, LinkTypeEnum::values()) &&
            $createLinkDTO->for::class === (self::FOR_CLASSES[$createLinkDTO->type] ?? null)
        ) {
            return;
        }

        throw new LinkException('The link should be for something and must have a valid type.', 422);
    }
}
