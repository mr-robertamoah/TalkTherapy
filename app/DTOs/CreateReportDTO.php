<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Post;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateReportDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Report $report = null;
    public Counsellor|User|null $addedby = null;
    public Post|Session|Therapy|null $reportable = null;
    public ?string $description = null;
    public ?array $data = [];
    public ?array $files = [];
    public ?array $deletedFiles = [];
}