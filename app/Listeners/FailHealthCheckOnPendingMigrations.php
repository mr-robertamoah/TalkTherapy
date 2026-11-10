<?php

namespace App\Listeners;

use Illuminate\Database\Migrations\Migrator;
use RuntimeException;

// SCRUM-109: the production deploy pipeline runs `migrate --force` on every deploy now, but this
// is a backstop for whenever that step is ever skipped, fails silently, or a migration is added
// without a deploy (e.g. run directly against a database that isn't this app's own production
// host) -- Laravel's /up health check dispatches DiagnosingHealth, and throwing here makes that
// route visibly fail (500, exception message shown) instead of the app silently running with a
// stale schema until a request happens to hit the new code path.
class FailHealthCheckOnPendingMigrations
{
    public function handle(): void
    {
        $migrator = app(Migrator::class);

        if (! $migrator->repositoryExists()) {
            return;
        }

        $ran = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

        $pending = array_diff(array_keys($files), $ran);

        if (count($pending) > 0) {
            throw new RuntimeException(
                count($pending).' pending migration(s) have not been run: '.implode(', ', $pending)
            );
        }
    }
}
