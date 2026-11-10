<?php

use App\Models\Counsellor;
use App\Models\Session;
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
        // SCRUM-196/TT-2.2a: a private, counsellor-authored clinical observation tied to exactly
        // one session -- a direct FK, not polymorphic, since (unlike Message/fileables) there is
        // only ever one owning Session row regardless of whether that session's own `for` is a
        // Therapy or a GroupTherapy. Mirrors Discussion::session()'s existing direct-FK shape.
        // Deliberately not built on the Message model: Message's `confidential` is an opt-in
        // per-message toggle (and doesn't even hold on the Reverb broadcast path -- see
        // SCRUM-195), whereas a session note must be unconditionally private with no toggle.
        Schema::create('session_notes', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (not cascadeOnDelete) on both -- Session and Counsellor are both
            // soft-deletable, and this app force-deletes Counsellor rows on a schedule 60 days
            // after account deletion (AppService::purgeExpiredSoftDeletedCounsellors) while
            // deliberately leaving related historical records untouched. A session note is a
            // clinical audit record and must survive that purge; cascading would silently and
            // permanently destroy it. Mirrors the identical `transactions.organization_id`
            // precedent (2026_08_29_600000_add_organization_id_to_transactions_table.php).
            $table->foreignIdFor(Session::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Counsellor::class)->nullable()->constrained()->nullOnDelete();
            $table->text('content');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_notes');
    }
};
