<?php

namespace App\Http\Controllers;

use App\Traits\ResolvesExceptionResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, ResolvesExceptionResponse, ValidatesRequests;

    // SCRUM-371: the one failure response (23 controllers each had their own copy). Only the app's
    // own messages are shown (ResolvesExceptionResponse). JSON for a script (axios/fetch);
    // otherwise back with the alert -- for an Inertia visit, and for a plain browser navigation.
    protected function returnFailure(Request $request, Throwable $th): JsonResponse|RedirectResponse
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        // An unexpected failure is shown as a generic message -- it must still reach the logs.
        if ($status === 500) {
            report($th);
        }

        if ($this->wantsJsonFailure($request)) {
            return response()->json(['message' => $message], $status);
        }

        $redirect = Redirect::back()->withErrors(['alert' => $message]);

        // A failed page visit (GET) lands on a page with no form to show errors.alert, so the layout
        // shows this one instead. Never for a form submission: its own onError already shows the
        // alert (SCRUM-371 QA).
        return $request->isMethod('GET') ? $redirect->with('failureAlert', $message) : $redirect;
    }

    // JSON for every request except an Inertia visit (which must get a redirect, never plain JSON)
    // and a browser navigation, which asks for HTML. The copies used acceptsJson(): true for a
    // browser's `*/*`, so a failed navigation showed raw JSON. expectsJson() alone is no better:
    // Inertia visits send X-Requested-With, and a request with no Accept header would lose its JSON.
    protected function wantsJsonFailure(Request $request): bool
    {
        return ! $request->header('X-Inertia') && ! str_contains((string) $request->header('Accept'), 'text/html');
    }
}
