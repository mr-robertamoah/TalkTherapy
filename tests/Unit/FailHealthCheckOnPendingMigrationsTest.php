<?php

use App\Listeners\FailHealthCheckOnPendingMigrations;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Regression test for SCRUM-109: the production deploy pipeline previously never ran
// `php artisan migrate`, so a pending migration could sit unapplied indefinitely with no visible
// failure state. This listener makes the /up health check fail loudly when that happens.

test('the health check passes when every migration has been run', function () {
    expect(fn () => (new FailHealthCheckOnPendingMigrations)->handle())
        ->not->toThrow(RuntimeException::class);
});

test('the health check fails when a migration is pending', function () {
    $lastMigration = DB::table('migrations')->orderByDesc('id')->first();
    DB::table('migrations')->where('id', $lastMigration->id)->delete();

    expect(fn () => (new FailHealthCheckOnPendingMigrations)->handle())
        ->toThrow(RuntimeException::class, $lastMigration->migration);
});

test('the DiagnosingHealth event is wired to this listener', function () {
    Event::fake([DiagnosingHealth::class]);

    Event::dispatch(new DiagnosingHealth);

    Event::assertListening(DiagnosingHealth::class, FailHealthCheckOnPendingMigrations::class);
});
