<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;
use App\Exceptions\TestimonialException;
use App\Models\Counsellor;

class EnsureCanCreateTestimonialAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        if ($createTestimonialDTO->addedby->hasTestimonial())
            throw new TestimonialException("This account already has a testimonial. Try to update it if you want it changed.", 422);

        if (
            $createTestimonialDTO->user->is($createTestimonialDTO->addedby) ||
            (
                $createTestimonialDTO->addedby::class == Counsellor::class && 
                $createTestimonialDTO->addedby?->user->is($createTestimonialDTO->user)
            )
        ) return;

        throw new TestimonialException("You are not allowed to add a testimonial with the account provided.", 422);
    }
}