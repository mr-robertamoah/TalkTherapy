<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Message;
use App\Models\Session;
use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateMessageDTO extends BaseDTO
{
    public ?String $content = null;
    public ?String $type = null;
    public ?String $status = null;
    public ?array $files = null;
    public ?array $deletedFiles = null;
    public ?Message $reply = null;
    public ?Message $message = null;
    public ?TherapyTopic $therapyTopic = null;
    public bool $confidential = false;
    public User|null $user = null;
    public User|Counsellor|null $from = null;
    public User|Counsellor|null $to = null;
    public Session|Discussion|null $for = null;
    
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