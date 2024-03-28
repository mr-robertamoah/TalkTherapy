<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use MrRobertAmoah\DTO\BaseDTO;

class VerifyCounsellorDTO extends BaseDTO
{
    public ?UploadedFile $licenseFile = null;
    public ?UploadedFile $nationalIdFile = null;
    public ?string $licenseNumber = null;
    public ?string $nationalIdNumber = null;
    public string|null|int $licensingAuthorityId = null;
    public ?User $user = null;
    public ?Counsellor $counsellor = null;
    public ?License $nationalIdLicense = null;
    public ?License $otherLicense = null;
    
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