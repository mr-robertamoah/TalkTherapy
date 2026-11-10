<?php

namespace App\Http\Controllers;

use App\Support\FailureResponse;
use App\Support\SuccessResponse;
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
    protected function failureResponse(Request $request, Throwable $th): JsonResponse|RedirectResponse
    {
        return FailureResponse::make($request, $th);
    }

    // SCRUM-380: the one success response (about 20 controllers each had their own private
    // returnSuccess). JSON $json for a script; otherwise $redirect -- or, without one, back with $json
    // flashed. Not named returnSuccess: a protected parent method would fatally clash with any child
    // controller still declaring a private one.
    protected function successResponse(Request $request, array $json = [], ?RedirectResponse $redirect = null): JsonResponse|RedirectResponse
    {
        return SuccessResponse::make($request, $json, $redirect);
    }
}
