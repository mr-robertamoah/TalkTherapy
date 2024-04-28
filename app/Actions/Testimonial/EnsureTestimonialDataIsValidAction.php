<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;
use App\Exceptions\TestimonialException;

class EnsureTestimonialDataIsValidAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO, string $action = 'create')
    {
        if (
            $this->canCreate($createTestimonialDTO) ||
            ($action == 'update' && $this->canUpdate($createTestimonialDTO))
        ) return;

        throw new TestimonialException("You have not provided enough data to {$action} testimonial.", 422);
    }

    private function canCreate(CreateTestimonialDTO $createTestimonialDTO)
    {
        return !!$createTestimonialDTO->content;
    }

    private function canUpdate(CreateTestimonialDTO $createTestimonialDTO)
    {
        return $createTestimonialDTO->content &&
            $createTestimonialDTO->content !== $createTestimonialDTO->testimonial->content;
    }
}