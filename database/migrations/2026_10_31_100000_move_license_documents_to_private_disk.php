<?php

use App\Actions\License\MoveLicenseDocumentsToPrivateDiskAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// SCRUM-300: data-only -- moves license documents uploaded before the fix off the public disk.
// See MoveLicenseDocumentsToPrivateDiskAction. Not reversible: putting license/ID documents back on
// a public URL is never a rollback anyone should get by accident.
return new class extends Migration
{
    public function up(): void
    {
        // Touches real files on disk, and RefreshDatabase migrates before a test can fake its disks
        // -- so never under the test runner. The move itself is covered by
        // MoveLicenseDocumentsToPrivateDiskActionTest.
        if (app()->runningUnitTests()) {
            return;
        }

        MoveLicenseDocumentsToPrivateDiskAction::new()->execute();

        $this->matchWebServerOwnership(storage_path('app/private/license-documents'));

        // The old public/storage/licenses symlink outlives its config entry (storage:link creates
        // links but never removes stale ones). Its target is empty now, but drop it anyway.
        if (is_link(public_path('storage/licenses')) && ! unlink(public_path('storage/licenses'))) {
            Log::error('Could not remove the stale public/storage/licenses symlink -- remove it by hand.');
        }
    }

    // Superseded for new migrations by MovePublicFilesToPrivateDiskAction::handOwnershipToWebServer()
    // (SCRUM-336); kept here because this migration has already run.
    // Migrations run as root (docker compose exec) or the deploy user (EC2), not the web server
    // user who must read and write these files -- so hand the new tree to whoever owns the public
    // storage directory, which is the web server. Only root can chown; anyone else is told to.
    private function matchWebServerOwnership(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $owner = fileowner(storage_path('app/public'));
        $group = filegroup(storage_path('app/public'));
        $paths = [$root, ...array_map(
            fn ($item) => $item->getPathname(),
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST)),
        )];

        foreach ($paths as $path) {
            if (! (@chown($path, $owner) && @chgrp($path, $group))) {
                Log::warning("Could not hand {$root} to the web server user -- run: chown -R \$(stat -c %U:%G storage/app/public) {$root}");

                return;
            }
        }
    }

    public function down(): void
    {
        //
    }
};
