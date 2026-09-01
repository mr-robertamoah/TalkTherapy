<?php

namespace App\DTOs;

use App\Models\Request as ModelsRequest;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class RequestResponseDTO extends BaseDTO
{
    public ?User $user = null;

    public ?string $response = null;

    public ?ModelsRequest $request = null;

    // Optional, currently only consumed by RejectSessionScheduleProposalAction (SCRUM-207/
    // TT-2.5b, "Option C": rejecting with a reason like "please propose a new time" is a
    // distinct choice from a bare reject) -- every other RespondTo*RequestAction ignores it.
    public ?string $reason = null;

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
