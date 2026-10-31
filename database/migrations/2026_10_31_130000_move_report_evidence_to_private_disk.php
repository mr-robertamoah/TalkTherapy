<?php

use App\Actions\File\MovePublicFilesToPrivateDiskAction;
use App\Models\File;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// SCRUM-337: data-only -- moves report evidence uploaded before the fix out of the public others/
// folder. Only files attached to a report: others/ also holds post and how-to images, which stay
// public, so there is no directory sweep. Not reversible, like the other private-storage moves.
return new class extends Migration
{
    public function up(): void
    {
        // Touches real files on disk -- never under the test runner (see 2026_10_31_110000).
        if (app()->runningUnitTests()) {
            return;
        }

        $move = MovePublicFilesToPrivateDiskAction::new();
        $move->execute('reports', 'others', 'report_evidence', sweepDirectory: false);
        // Host-run installs (FILESYSTEM_DISK=local), as 2026_10_31_120000 does for messages -- a no-op
        // where nothing was ever stored there.
        $move->execute('reports', 'others', 'report_evidence', 'local', sweepDirectory: false);
        $move->handOwnershipToWebServer(storage_path('app/private/report-evidence'));

        $this->reportWhatStayedPublic();
    }

    // Without the sweep, a file in others/ with no row (a report deleted while its file delete
    // silently failed, an interrupted upload) stays public and can't be told apart from a post
    // image by its path. Never moved blindly -- but made visible (devops review).
    private function reportWhatStayedPublic(): void
    {
        $stillPublic = File::query()->where('storage', 'public')->whereHas('reports')->count();
        if ($stillPublic > 0) {
            Log::error('Report evidence is still on the public disk after the move.', ['count' => $stillPublic]);
        }

        $known = File::query()->where('storage', 'public')->where('path', 'others')->pluck('name')->all();
        $orphans = array_values(array_filter(
            Storage::disk('public')->files('others'),
            fn (string $path) => ! in_array(basename($path), $known, true),
        ));

        if ($orphans) {
            Log::warning('Files in public others/ have no database row -- they stay public. Check whether any is report evidence and remove it by hand.', ['paths' => $orphans]);
        }
    }

    public function down(): void
    {
        //
    }
};
