<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetModelsForAdminDTO extends BaseDTO
{
    public ?User $user = null;

    public ?string $filterType = null;

    public ?string $username = null;

    public ?string $name = null;

    public ?string $age = null;

    public string|bool|null $filterValue = null;

    // SCRUM-390: the page's search (App\Support\AdminSearch::term()).
    public ?string $search = null;

    /**
     * assign data (filled or validated) to the dto properties as an
     * addition to the fromRequest function.
     *
     * @param  Illuminate\Http\Request  $request
     * @return MrRobertAmoah\DTO\BaseDTO
     */
    protected function fromRequestExtension(Request $request): self
    {
        return $this;
    }

    /**
     * assign values of keys of an array to the corresponding dto properties
     * as an additional function for the fromData function.
     *
     * @return MrRobertAmoah\DTO\BaseDTO
     */
    protected function fromArrayExtension(array $data = []): self
    {
        return $this;
    }

    /**
    * uncomment and use this function if you want to
    * customize the key and value pairs
    * to be used to create your dto and still get the
    * other features of the dto
    */
    //    public function requestToArray($request)
    //    {
    //       return [];
    //    }
}
