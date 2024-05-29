<?php

namespace App\Http\Controllers;

use App\Services\AppService;
use App\Services\TestimonialService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class AboutController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('About', [
            'testimonials' => TestimonialService::new()->getTestimonialsForAboutPage()
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
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);

        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
