<?php

use App\Actions\File\MovePublicFilesToPrivateDiskAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// SCRUM-336: data-only -- moves chat attachments uploaded before the fix off the public disk. See
// MovePublicFilesToPrivateDiskAction. Not reversible: putting therapy chat files back on a public
// URL is never a rollback anyone should get by accident.
return new class extends Migration
{
    public function up(): void
    {
        // Touches real files on disk, and RefreshDatabase migrates before a test can fake its disks
        // -- so never under the test runner. The move itself is covered by the action's own tests.
        if (app()->runningUnitTests()) {
            return;
        }

        $move = MovePublicFilesToPrivateDiskAction::new();
        $move->execute('messages', 'messages', 'message_attachments');
        $move->handOwnershipToWebServer(storage_path('app/private/message-attachments'));

        // The old public/storage/messages symlink outlives its config entry (storage:link creates
        // links but never removes stale ones). Its target is empty now, but drop it anyway.
        if (is_link(public_path('storage/messages')) && ! unlink(public_path('storage/messages'))) {
            Log::error('Could not remove the stale public/storage/messages symlink -- remove it by hand.');
        }
    }

    public function down(): void
    {
        //
    }
};
