<?php

namespace Database\Seeders;

// SCRUM-429: every seeder makes demo accounts with known passwords (and published two-factor keys) -- only on local
// and testing environments, never anywhere holding real people (security review: an allowlist, so a "staging" or
// "prod" never qualifies by accident). ALLOW_DEMO_SEED=true overrides elsewhere, as a one-off on the command line (not in an
// env file, where it would stay on) -- never in production. A refusal
// exits cleanly: the Docker entrypoint seeds a fresh database, and a failure there would stop the container starting.
trait RefusesOutsideLocal
{
    protected function refusedHere(): bool
    {
        // The override never reaches production (security review: a value left in an env file would stay on).
        if (app()->environment(['local', 'testing']) || (! app()->environment('production') && filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL))) {
            return false;
        }

        $this->command?->error('Not seeding: APP_ENV is '.app()->environment().', and these are demo accounts with known passwords. Only on local or testing (or, outside production, ALLOW_DEMO_SEED=true for a one-off).');

        return true;
    }
}
