<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;
use App\Exceptions\TestimonialException;
use App\Models\Counsellor;

class EnsureCanUpdateTestimonialAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        if (
            $createTestimonialDTO->user->isAdmin() ||
            $createTestimonialDTO->user->is($createTestimonialDTO->testimonial->addedby) ||
            (
                $createTestimonialDTO->testimonial->addedby::class == Counsellor::class && 
                $createTestimonialDTO->testimonial->addedby->user->is($createTestimonialDTO->user)
            )
        ) return;

        throw new TestimonialException("You are not allowed to update/delete this testimonial.", 422);
    }
}