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
    }
}
