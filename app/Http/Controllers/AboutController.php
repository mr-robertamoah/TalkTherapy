<?php

namespace App\Http\Controllers;

use App\Services\TestimonialService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AboutController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('About', [
            'testimonials' => TestimonialService::new()->getTestimonialsForAboutPage()
        ]);
    }
}
