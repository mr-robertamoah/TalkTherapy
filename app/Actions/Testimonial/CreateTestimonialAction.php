<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;

class CreateTestimonialAction extends Action
{
    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        $report = $createTestimonialDTO->addedby->addedTestimonials()->create([
            'content' => $createTestimonialDTO->content,
        ]);

        return $report->refresh();
    }
}