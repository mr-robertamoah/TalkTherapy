<?php

namespace App\Actions\Testimonial;

use App\Actions\Action;
use App\DTOs\CreateTestimonialDTO;

class UpdateTestimonialAction extends Action
{
    private array $data = [];

    public function execute(CreateTestimonialDTO $createTestimonialDTO)
    {
        $this->setData($createTestimonialDTO);

        $createTestimonialDTO->testimonial->update($this->data);

        return $createTestimonialDTO->testimonial->refresh();
    }

    private function setData(CreateTestimonialDTO $createTestimonialDTO)
    {
        if ($createTestimonialDTO->content && $createTestimonialDTO->content !== $createTestimonialDTO->testimonial->content)
            $this->data['content'] = $createTestimonialDTO->content;
    }
}