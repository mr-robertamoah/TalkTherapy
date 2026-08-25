<?php

namespace App\Http\Controllers;

use App\Services\AppService;
use App\Services\TestimonialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
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
