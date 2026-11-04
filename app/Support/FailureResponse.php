<?php

namespace App\Support;

use App\Exceptions\AppException;
use App\Exceptions\HasErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

// SCRUM-371/374: the one failure response -- used by the base Controller's returnFailure() (for the
// controllers that catch) and by bootstrap/app.php's AppException renderer (for refusals thrown
// anywhere else), so the two can't drift. Only the app's own refusals show their message.
class FailureResponse
{
    // A thrown exception's code is only trusted as an HTTP status when it is one of the app's own
    // refusals (SCRUM-95: an AppException) AND a real 400-599. Anything else -- the default code 0,
    // or a third-party exception whose code is an upstream HTTP status -- is a generic 500.
    public static function status(Throwable $th): int
    {
        if (! $th instanceof AppException) {
            return 500;
        }

        return ($th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 500;
    }

    // Decided from the resolved status, so an uncoded exception never shows its raw message.
    public static function message(Throwable $th, int $status): string
    {
        return $status == 500 ? 'Something unfortunate happened. Please try again shortly.' : $th->getMessage();
    }

    // SCRUM-288: a stable code for a refusal the frontend must tell apart (HasErrorCode), only for the
    // app's own refusals that keep their status.
    public static function code(Throwable $th): ?string
    {
        return $th instanceof HasErrorCode && self::status($th) !== 500 ? $th->errorCode() : null;
    }

    // The JSON body: the message, plus the code when there is one.
    public static function body(Throwable $th): array
    {
        $code = self::code($th);

        return ['message' => self::message($th, self::status($th)), ...($code ? ['code' => $code] : [])];
    }

    // For the controllers that always answer JSON and catch inline (no report: their callers decide).
    public static function json(Throwable $th): JsonResponse
    {
        return response()->json(self::body($th), self::status($th));
    }

    public static function make(Request $request, Throwable $th): JsonResponse|RedirectResponse
    {
        $status = self::status($th);
        $message = self::message($th, $status);

        // An unexpected failure is shown as a generic message -- it must still reach the logs.
        if ($status === 500) {
            report($th);
        }

        if ($request->wantsJsonResponse()) {
            return response()->json(self::body($th), $status);
        }

        // errors.alert stays the message; errors.alertCode carries the code, when there is one.
        $code = self::code($th);
        $errors = ['alert' => $message, ...($code ? ['alertCode' => $code] : [])];

        // Back -- unless "back" is this very page (a refreshed page that now fails), which would loop.
        $redirect = ($request->isMethod('GET') && url()->previous() === $request->fullUrl())
            ? Redirect::route('home')->withErrors($errors)
            : Redirect::back()->withErrors($errors);

        // A failed page visit (GET) lands on a page with no form to show errors.alert, so the layout
        // shows this one instead. Never for a form submission: its own onError already shows it.
        // Kept until a page shows it (HandleInertiaRequestsV2 pulls it), not flashed: a flash lasts one
        // request, and "back" can itself redirect (e.g. /login straight after signing in), which used
        // the flash up before any page rendered it.
        if ($request->isMethod('GET') && $request->hasSession()) {
            $request->session()->put('failureAlert', $message);
        }

        return $redirect;
    }
}
