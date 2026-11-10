<?php

use App\Models\Counsellor;
use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SCRUM-202/TT-2.3a: a private, counsellor-authored note tied to exactly one Message
        // (individual therapy, group therapy, or discussion chat -- Message::for is polymorphic
        // over Session/Discussion, and this table doesn't need to care which). Same
        // unconditionally-private, no-toggle rationale as session_notes: deliberately not reusing
        // Message's own `confidential` flag (opt-in, and separately known to leak over Reverb --
        // SCRUM-195).
        Schema::create('message_notes', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (not cascadeOnDelete) on both, mirroring session_notes' identical
            // rationale: Message and Counsellor are both soft-deletable, and Counsellor rows are
            // force-deleted on a schedule after account deletion
            // (AppService::purgeExpiredSoftDeletedCounsellors) without touching related
            // historical records. A message note is a clinical audit record and must survive
            // that purge.
            $table->foreignIdFor(Message::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Counsellor::class)->nullable()->constrained()->nullOnDelete();
            $table->text('content');
            $table->timestamps();
            $table->softDeletes();

            // Max one note per counsellor per message (product decision, SCRUM-22/TT-2.3) --
            // enforced at the DB level rather than only in the Ensure*Action, matching the
            // existing counsellor_discussion unique-index precedent
            // (2026_08_25_100000_add_unique_index_to_counsellor_discussion_table.php).
            $table->unique(['message_id', 'counsellor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_notes');
    }
};
