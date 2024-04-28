<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\EnsureIsAdminAction;
use App\Actions\Testimonial\CreateTestimonialAction;
use App\Actions\Testimonial\DeleteTestimonialAction;
use App\Actions\Testimonial\EnsureCanCreateTestimonialAction;
use App\Actions\Testimonial\EnsureCanUpdateTestimonialAction;
use App\Actions\Testimonial\EnsureTestimonialDataIsValidAction;
use App\Actions\Testimonial\EnsureTestimonialExistsAction;
use App\Actions\Testimonial\MarkTestimonialAction;
use App\Actions\Testimonial\UpdateTestimonialAction;
use App\DTOs\CreateTestimonialDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\TestimonialResource;
use App\Models\Testimonial;

class TestimonialService extends Service
{
    public function getTestimonialsForAboutPage()
    {
        $query = Testimonial::query();

        $query
            ->whereUse()
            ->inRandomOrder()
            ->limit(2);

        return TestimonialResource::collection($query->get());
    }

    public function createTestimonial(CreateTestimonialDTO $createTestimonialDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createTestimonialDTO,
            "You are not allowed to use the account to add a testimonial."
        );

        EnsureCanCreateTestimonialAction::new()->execute($createTestimonialDTO);

        EnsureTestimonialDataIsValidAction::new()->execute($createTestimonialDTO);

        $testimonial = CreateTestimonialAction::new()->execute($createTestimonialDTO);

        // AppService::new()->alertAdminWithTestimonial($testimonial);

        return $testimonial;
    }

    public function updateTestimonial(CreateTestimonialDTO $createTestimonialDTO)
    {
        EnsureTestimonialExistsAction::new()->execute($createTestimonialDTO);

        EnsureCanUpdateTestimonialAction::new()->execute($createTestimonialDTO);

        EnsureTestimonialDataIsValidAction::new()->execute($createTestimonialDTO, 'update');

        return UpdateTestimonialAction::new()->execute($createTestimonialDTO);
    }

    public function deleteTestimonial(CreateTestimonialDTO $createTestimonialDTO)
    {
        EnsureTestimonialExistsAction::new()->execute($createTestimonialDTO);

        EnsureCanUpdateTestimonialAction::new()->execute($createTestimonialDTO);

        return DeleteTestimonialAction::new()->execute($createTestimonialDTO);
    }

    public function markTestimonial(CreateTestimonialDTO $createTestimonialDTO)
    {
        EnsureTestimonialExistsAction::new()->execute($createTestimonialDTO);

        EnsureIsAdminAction::new()->execute($createTestimonialDTO);

        return MarkTestimonialAction::new()->execute($createTestimonialDTO);
    }

    public function getTestimonials(CreateTestimonialDTO $createTestimonialDTO)
    {
        $query = Testimonial::query();

        $query->when($createTestimonialDTO->addedby, function ($query) use ($createTestimonialDTO) {
            $query->whereAddedby($createTestimonialDTO->addedby);
        });

        $query
            ->whereLike($createTestimonialDTO->like)
            ->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}