<?php

use App\Http\Middleware\StoreVisitationMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;

// SCRUM-136: bootstrap/app.php called $middleware->use([StoreVisitationMiddleware::class]),
// which *replaces* Laravel's entire default global middleware stack rather than appending to
// it (Illuminate\Foundation\Configuration\Middleware::use() sets $this->global directly, and
// getGlobalMiddleware() only falls back to the framework defaults when $this->global is empty).
// This silently disabled TrustProxies, HandleCors, PreventRequestsDuringMaintenance, and others
// app-wide -- switching to append() restores them alongside StoreVisitationMiddleware.

test('the global middleware stack still includes the framework defaults, not just StoreVisitationMiddleware', function () {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect($global)->toContain(TrustProxies::class)
        ->toContain(HandleCors::class)
        ->toContain(PreventRequestsDuringMaintenance::class)
        ->toContain(StoreVisitationMiddleware::class);

    // StoreVisitationMiddleware reads $request->ip() -- it must run after TrustProxies has
    // resolved the real client IP, not before, or it silently records the proxy's IP instead.
    expect(array_search(TrustProxies::class, $global))
        ->toBeLessThan(array_search(StoreVisitationMiddleware::class, $global));
});
