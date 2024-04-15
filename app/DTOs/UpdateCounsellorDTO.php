<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use MrRobertAmoah\DTO\BaseDTO;

class UpdateCounsellorDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Counsellor $counsellor = null;
    public ?String $name = null;
    public ?String $about = null;
    public ?String $email = null;
    public ?String $phone = null;
    public ?bool $contactVisible = null;
    public String|int|null $professionId = null;
    public ?bool $deleteAvatar = false;
    public ?bool $deleteCover = false;
    public ?array $selectedCases = null;
    public ?array $selectedLanguages = null;
    public ?array $selectedReligions = null;
    public ?UploadedFile $avatar = null;
    public ?UploadedFile $cover = null;
    public ?Request $request = null;
}