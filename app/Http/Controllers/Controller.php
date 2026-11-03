<?php

namespace App\Http\Controllers;

use App\Support\FailureResponse;
use App\Traits\ResolvesExceptionResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, ResolvesExceptionResponse, ValidatesRequests;

    // SCRUM-371: the one failure response (23 controllers each had their own copy). Only the app's
    // own messages are shown (ResolvesExceptionResponse). JSON for a script (axios/fetch);
    // otherwise back with the alert -- for an Inertia visit, and for a plain browser navigation.
    protected function returnFailure(Request $request, Throwable $th): JsonResponse|RedirectResponse
    {
        return FailureResponse::make($request, $th);
    }
}
