<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateSessionDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Session $session = null;
    public ?String $name = null;
    public ?String $landmark = null;
    public float|string|null $latitude = null;
    public float|string|null $longitude = null;
    public ?String $about = null;
    public Carbon|string|null $startTime = null;
    public Carbon|string|null $endTime = null;
    public Therapy|GroupTherapy|null $for = null;
    public ?String $type = null;
    public ?String $paymentType = null;
    public ?array $cases = null;
    public ?array $topics = null;
    
    /**
     * assign data (filled or validated) to the dto properties as an 
     * addition to the fromRequest function.
     *
     * @param  Illuminate\Http\Request  $request
     * @return MrRobertAmoah\DTO\BaseDTO
     */
    protected function fromRequestExtension(Request $request) : self
    {
        return $this;
    }

    /**
     * assign values of keys of an array to the corresponding dto properties 
     * as an additional function for the fromData function.
     *
     * @param  array  $data
     * @return MrRobertAmoah\DTO\BaseDTO
     */
    protected function fromArrayExtension(array $data = []) : self
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