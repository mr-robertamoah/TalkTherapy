<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateTestimonialDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Testimonial $testimonial = null;
    public User|Counsellor|null $addedby = null;
    public ?string $content = null;
    public ?string $like = null;
    public bool $use = false;
}