<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateTestimonialDTO;
use App\Http\Resources\TestimonialResource;
use App\Models\Testimonial;
use App\Services\TestimonialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class TestimonialController extends Controller
{
    public function createTestimonial(Request $request)
    {
        try {
            $testimonial = TestimonialService::new()->createTestimonial(
                CreateTestimonialDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return $this->returnSuccess($request, $testimonial);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    // Every lookup below reads $request->route('testimonialId') rather than the magic
    // ->testimonialId property -- see the identical fix/rationale in SessionController
    // (SCRUM-116/SCRUM-130).
    public function updateTestimonial(Request $request)
    {
        $testimonial = Testimonial::find($request->route('testimonialId'));
        try {
            $testimonial = TestimonialService::new()->updateTestimonial(
                CreateTestimonialDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'testimonial' => $testimonial,
                ])
            );

            return $this->returnSuccess($request, $testimonial);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function deleteTestimonial(Request $request)
    {
        $testimonial = Testimonial::find($request->route('testimonialId'));
        try {
            TestimonialService::new()->deleteTestimonial(
                CreateTestimonialDTO::new()->fromArray([
                    'user' => $request->user(),
                    'testimonial' => $testimonial,
                ])
            );

            return $this->returnSuccess($request, $testimonial);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function markTestimonial(Request $request)
    {
        $testimonial = Testimonial::find($request->route('testimonialId'));
        try {
            TestimonialService::new()->markTestimonial(
                CreateTestimonialDTO::new()->fromArray([
                    'user' => $request->user(),
                    'testimonial' => $testimonial,
                    'use' => $request->boolean('use'),
                ])
            );

            return $this->returnSuccess($request, $testimonial);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getTestimonials(Request $request)
    {
        try {
            $testimonials = TestimonialService::new()->getTestimonials(
                CreateTestimonialDTO::new()->fromArray([
                    'user' => $request->user(),
                    'like' => $request->like,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return TestimonialResource::collection($testimonials);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Testimonial $testimonial)
    {
        $testimonial = new TestimonialResource($testimonial);

        if ($request->acceptsJson()) {
            return response()->json(['testimonial' => $testimonial]);
        }

        return Redirect::back()->with(['testimonial' => $testimonial]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        if ($request->acceptsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return Redirect::back()->withErrors(['alert' => $message]);
    }
}
