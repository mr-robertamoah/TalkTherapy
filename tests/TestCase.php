<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Independent of whatever mechanism (phpunit.xml, .env.testing, config caching, ...) is
        // supposed to route tests to an isolated DB: RefreshDatabase truncates/migrates whatever
        // connection this resolves to, so if it's ever anything other than sqlite, tests would
        // silently wipe the real dev database instead of loudly failing. See SCRUM-60.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException(
                'Tests must run against the sqlite connection, got ['.DB::connection()->getDriverName().
                ']. Refusing to run RefreshDatabase against a non-test database.'
            );
        }

        // Same class of mechanism-agnostic safety net as the sqlite check above: a shared,
        // non-isolated cache store caused real cross-process corruption of rate-limiter counters
        // under `php artisan test --parallel` (SCRUM-105). If config/cache.php's env-lookup ever
        // regresses (e.g. reverting to only reading the legacy CACHE_DRIVER), fail loudly here
        // instead of reintroducing that flake silently.
        if (config('cache.default') !== 'array') {
            throw new RuntimeException(
                'Tests must run against the array cache store, got ['.config('cache.default').
                ']. Refusing to run tests against a shared, non-isolated cache store.'
            );
        }
    }
}
