<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;
use App\Exceptions\TestimonialException;

class EnsureTestimonialExistsAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        if (
            $createTestimonialDTO->testimonial
        ) return;

        throw new TestimonialException("You cannot perform this action because the testimonial was not found.", 422);
    }
}