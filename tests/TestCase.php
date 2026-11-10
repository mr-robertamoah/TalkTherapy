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

        $this->ensureIsolatedTestDatabase();

        // Same class of mechanism-agnostic safety net as the database check: a shared,
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

    // RefreshDatabase calls this before it migrates -- so the guard below runs before the first
    // wipe, not after it (devops review, SCRUM-363). setUp() calls it again for tests without
    // RefreshDatabase.
    protected function beforeRefreshingDatabase()
    {
        $this->ensureIsolatedTestDatabase();
    }

    private function ensureIsolatedTestDatabase(): void
    {
        // Independent of whatever mechanism (phpunit.xml, .env.testing, config caching, ...) is
        // supposed to route tests to an isolated DB: RefreshDatabase truncates/migrates whatever
        // connection this resolves to, so if it's ever anything other than sqlite, tests would
        // silently wipe the real dev database instead of loudly failing. See SCRUM-60.
        // SCRUM-363: the one exception is phpunit.mysql.xml's own throwaway MySQL database, used by
        // CI to run the MySQL-only trigger tests -- named exactly, so a dev database never matches.
        $isMysqlCiDatabase = DB::connection()->getDriverName() === 'mysql'
            && DB::connection()->getDatabaseName() === 'talktherapy_mysql_ci';

        if (DB::connection()->getDriverName() !== 'sqlite' && ! $isMysqlCiDatabase) {
            throw new RuntimeException(
                'Tests must run against the sqlite connection, got ['.DB::connection()->getDriverName().
                ']. Refusing to run RefreshDatabase against a non-test database.'
            );
        }
    }
}
