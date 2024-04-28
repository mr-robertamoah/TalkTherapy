<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;
use App\Services\FileService;

class DeleteTestimonialAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        return $createTestimonialDTO->testimonial->delete();
    }
}