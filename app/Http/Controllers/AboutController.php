<?php

namespace App\Http\Controllers;

use App\Services\AppService;
use App\Services\TestimonialService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

class AboutController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('About', [
            'testimonials' => TestimonialService::new()->getTestimonialsForAboutPage(),
        ]);
    }

    public function getStats(Request $request)
    {
        try {
            return AppService::new()->getStats();
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }
}
