<?php

use App\Actions\File\MovePublicFilesToPrivateDiskAction;
use Illuminate\Database\Migrations\Migration;

// SCRUM-341: chat attachments uploaded by a host-run install, whose default disk (FILESYSTEM_DISK)
// was the already-private 'local', not 'public' -- so 2026_10_31_110000 left them where they were.
// They join the rest on message_attachments, which is now the only disk attachments are served
// from. A no-op wherever uploads went to 'public' (Docker, production). Not reversible.
return new class extends Migration
{
    public function up(): void
    {
        // Touches real files on disk, and RefreshDatabase migrates before a test can fake its disks
        // -- so never under the test runner. Covered by MovePublicFilesToPrivateDiskActionTest.
        if (app()->runningUnitTests()) {
            return;
        }

        $move = MovePublicFilesToPrivateDiskAction::new();
        $move->execute('messages', 'messages', 'message_attachments', 'local');
        $move->handOwnershipToWebServer(storage_path('app/private/message-attachments'));
    }

    public function down(): void
    {
        //
    }
};
