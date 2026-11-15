<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-37 (the user's decision): every check-in route is a plain 404 until CHECK_INS_ENABLED is switched on, after
// the clinical and legal wording is read (on by default only in local and testing).
class EnsureCheckInsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('check_ins.enabled'), 404);

        return $next($request);
    }
}
