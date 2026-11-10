<?php

namespace App\DTOs;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetCounsellorCalendarSessionsDTO extends BaseDTO
{
    public ?User $user = null;

    public Carbon|string|null $startDate = null;

    public Carbon|string|null $endDate = null;

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
}
