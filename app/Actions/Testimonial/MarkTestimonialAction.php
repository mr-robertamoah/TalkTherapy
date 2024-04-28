<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;

class MarkTestimonialAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        $createTestimonialDTO->testimonial->update([
            'use' => $createTestimonialDTO->use
        ]);

        return $createTestimonialDTO->testimonial->refresh();
    }
}