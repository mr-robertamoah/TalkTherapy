<?php

use App\Jobs\StoreVisitationJob;
use Illuminate\Support\Facades\Bus;

// Regression test for SCRUM-137: a stray semicolon after the if-condition made the /login guard
// a no-op, so StoreVisitationJob dispatched unconditionally on every request, including /login.

test('visiting /login does not dispatch a StoreVisitationJob', function () {
    Bus::fake();

    $this->get('/login');

    Bus::assertNotDispatched(StoreVisitationJob::class);
});

test('visiting a non-login route dispatches a StoreVisitationJob', function () {
    Bus::fake();

    $this->get('/');

    Bus::assertDispatched(StoreVisitationJob::class);
});
