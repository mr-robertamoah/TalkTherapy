<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\GetModelWithModelNameAndIdAction;
use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\ChangeLinkStatusAction;
use App\Actions\Link\DeleteLinkAction;
use App\Actions\Link\EnsureCanUpdateLinkAction;
use App\Actions\Link\EnsureCanUseLinkAction;
use App\Actions\Link\EnsureLinkDataIsValidAction;
use App\Actions\Link\EnsureLinkExistsAction;
use App\Actions\Link\PerformLinkAction;
use App\Actions\User\EnsureUserExistsAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetLinksDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\LinkResource;
use App\Models\Link;

class LinkService extends Service
{
    public function performAction(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        EnsureCanUseLinkAction::new()->execute($createLinkDTO);

        return PerformLinkAction::new()->execute($createLinkDTO);
    }

    public function createLink(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureAddedbyIsValidAction::new()->execute(dto: $createLinkDTO, force: true);

        EnsureLinkDataIsValidAction::new()->execute($createLinkDTO);

        return CreateLinkAction::new()->execute($createLinkDTO);
    }

    public function createMultipleLinks(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureAddedbyIsValidAction::new()->execute(dto: $createLinkDTO, force: true);
        
        $links = [];
        foreach ($createLinkDTO->linksData as $data) {
            $dto = CreateLinkDTO::new()->fromArray([
                'addedby' => $createLinkDTO->addedby,
                'type' => $createLinkDTO->type,
                'for' => GetModelWithModelNameAndIdAction::new()->execute($data['forType'], $data['forId']),
                'to' => GetModelWithModelNameAndIdAction::new()->execute($data['toType'], $data['toId']),
            ]);

            EnsureLinkDataIsValidAction::new()->execute($dto);
            
            $links[] = CreateLinkAction::new()->execute($dto);
        }

        return $links;
    }

    public function changeLinkStatus(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        EnsureCanUpdateLinkAction::new()->execute($createLinkDTO);

        return ChangeLinkStatusAction::new()->execute($createLinkDTO);
    }

    public function changeMultipleLinkStatuses(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        $links = [];

        foreach ($createLinkDTO->linksData as $data) {
            $dto = CreateLinkDTO::new()->fromArray([
                'user' => $createLinkDTO->user,
                'link' => Link::find($data['id']),
            ]);

            EnsureLinkExistsAction::new()->execute($dto);

            EnsureCanUpdateLinkAction::new()->execute($dto);
            
            $links[] = ChangeLinkStatusAction::new()->execute($dto);
        }

        return $links;
    }

    public function deleteLink(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        EnsureCanUpdateLinkAction::new()->execute($createLinkDTO);

        return DeleteLinkAction::new()->execute($createLinkDTO);
    }

    public function deleteMultipleLinks(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        foreach ($createLinkDTO->linksData as $data) {
            $dto = CreateLinkDTO::new()->fromArray([
                'user' => $createLinkDTO->user,
                'link' => Link::find($data['id']),
            ]);

            EnsureLinkExistsAction::new()->execute($dto);

            EnsureCanUpdateLinkAction::new()->execute($dto);
            
            DeleteLinkAction::new()->execute($dto);
        }
    }

    public function getLinks(GetLinksDTO $getLinksDTO)
    {
        if (
            $getLinksDTO->addedby &&
            !$getLinksDTO->addedby->is($getLinksDTO->user) &&
            !$getLinksDTO->addedby->is($getLinksDTO->user?->counsellor)
        ) return [];
    
        $query = Link::query();

        if ($getLinksDTO->addedby)
            $query->whereAddedby($getLinksDTO->addedby);
        else if ($getLinksDTO->user)
                $query->whereAddedby($getLinksDTO->user);

        if ($getLinksDTO->for)
            $query->whereFor($getLinksDTO->for);

        if ($getLinksDTO->to)
            $query->whereTo($getLinksDTO->to);

        if ($getLinksDTO->state)
            $query->whereState($getLinksDTO->state);

        if ($getLinksDTO->type)
            $query->whereType($getLinksDTO->type);

        return LinkResource::collection(
            $query->latest()->paginate(PaginationEnum::preferencesPagination->value)
        );
    }
}