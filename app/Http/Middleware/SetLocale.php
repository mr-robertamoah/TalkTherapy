<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-479 (TT-9.3a): the language this request is answered in -- the account's choice, else this browser's
// (tt_locale), else English. Only an enabled one is ever applied. The browser's own language (Accept-Language) is
// never applied: when nothing has been chosen it's kept on the request as `suggestedLocale`, for the page to offer.
//
// After EndStaleSessions, so the signed-in user it reads is one that's still allowed in -- and it's the one already
// resolved there (the web guard, from the session), so this adds no query.
class SetLocale
{
    public const SUGGESTED = 'suggestedLocale';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->hasSession() ? Auth::guard('web')->user() : null;
        $chosen = Locales::chosen($user instanceof User ? $user : null, $request);

        App::setLocale($chosen ?? Locales::DEFAULT);

        // Only for someone who hasn't chosen: not an account with a saved language, nor a browser that picked one.
        $hasSaved = ($user instanceof User && $user->locale !== null) || $chosen !== null;
        $request->attributes->set(self::SUGGESTED, $hasSaved ? null : Locales::suggested($request));

        return $next($request);
    }
}
