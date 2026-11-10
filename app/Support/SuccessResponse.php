<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

// SCRUM-380: the one success response -- FailureResponse's counterpart, used by the base
// Controller's successResponse() (about 20 controllers each had their own private copy). JSON for a
// script (axios/fetch); otherwise the redirect -- for an Inertia visit, and for a plain browser
// navigation. Decided by the same Request::wantsJsonResponse() rule as a failure.
class SuccessResponse
{
    // $json is the JSON body. Without a $redirect, the visit goes back with that same data flashed
    // (the resource controllers' long-standing shape: ['post' => PostResource]). Built only when it
    // is used, so a JSON caller never flashes anything into the session.
    public static function make(Request $request, array $json = [], ?RedirectResponse $redirect = null): JsonResponse|RedirectResponse
    {
        if ($request->wantsJsonResponse()) {
            return response()->json($json);
        }

        return $redirect ?? Redirect::back()->with($json);
    }
}
