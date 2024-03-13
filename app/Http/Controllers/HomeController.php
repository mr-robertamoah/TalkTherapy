<?php

namespace App\Http\Controllers;

use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function goHome(Request $request)
    {
        $message = session('message');
        $page = Inertia::render('Home', [
            'counsellorCreationStep' => GetCounsellorCreationStepOfUserAction::new()->execute($request->user())
        ]);

        if ($message) {
            $page->with('message', $message);
        }

        return $page;
    }
}
