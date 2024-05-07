<?php

namespace App\Services;

use App\Actions\EnsureIsAdminAction;
use App\Actions\HowTo\CreateHowToAction;
use App\Actions\HowTo\DeleteHowToAction;
use App\Actions\HowTo\EnsureHowToDataIsValidAction;
use App\Actions\HowTo\EnsureHowToExistsAction;
use App\Actions\HowTo\EnsurePositionsAreValidAction;
use App\Actions\HowTo\UpdateHowToAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\GetHowToDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\HowToResource;
use App\Models\HowTo;

class HowToService extends Service
{
    public function getHowTos(GetHowToDTO $getHowToDTO)
    {
        $query = HowTo::query();

        $query->when($getHowToDTO->name, function ($query) use ($getHowToDTO) {
            $query->whereNameLike($getHowToDTO->name);
        });

        $query->when($getHowToDTO->pageLike, function ($query) use ($getHowToDTO) {
            $query->wherePageLike($getHowToDTO->pageLike);
        });

        $query->orWhere(function ($query) {
            $query->whereAll();
        });

        return HowToResource::collection($query->paginate(PaginationEnum::preferencesPagination->value));
    }

    public function createHowTo(CreateHowToDTO $createHowToDTO)
    {
        EnsureIsAdminAction::new()->execute($createHowToDTO);

        EnsureHowToDataIsValidAction::new()->execute($createHowToDTO);

        EnsurePositionsAreValidAction::new()->execute($createHowToDTO);

        return CreateHowToAction::new()->execute($createHowToDTO);
    }

    public function updateHowTo(CreateHowToDTO $createHowToDTO)
    {
        EnsureHowToExistsAction::new()->execute($createHowToDTO);

        EnsureIsAdminAction::new()->execute($createHowToDTO);

        EnsureHowToDataIsValidAction::new()->execute($createHowToDTO, 'update');

        EnsurePositionsAreValidAction::new()->execute($createHowToDTO, 'update');

        return UpdateHowToAction::new()->execute($createHowToDTO);
    }

    public function deleteHowTo(CreateHowToDTO $createHowToDTO)
    {
        EnsureHowToExistsAction::new()->execute($createHowToDTO);

        EnsureIsAdminAction::new()->execute($createHowToDTO);

        return DeleteHowToAction::new()->execute($createHowToDTO);
    }
}