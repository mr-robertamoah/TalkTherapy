<?php

use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Models\User;
use App\Models\VideoSessionParticipant;
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
        // TT-3.2f-b/SCRUM-319: an append-only audit trail of every grant/revoke, modeled directly
        // on video_consents' own shape (architect decision, 2026-09-14) -- rows are NEVER deleted
        // or mutated except for the one narrow exception below. Revocation sets
        // revoked_at/revoked_by_user_id/revocation_reason on the EXISTING row; a member granted
        // again after a revoke gets a brand-new row, preserving the full grant -> revoke ->
        // re-grant history. "Currently granted" is always whereNull('revoked_at') on the latest
        // row for a video_session_participant_id, mirroring VideoConsent's own "currently valid"
        // derivation.
        //
        // Anchored to video_session_participants.id (a specific join-CYCLE row), not a bare
        // (video_session_id, participant_id) pair -- a member who leaves and rejoins gets a new
        // participant row, and anchoring here removes any ambiguity about whether a grant belongs
        // to the current join or a stale prior one.
        Schema::create('video_session_speaking_grants', function (Blueprint $table) {
            $table->id();
            // Explicit, short constraint names throughout this table -- the auto-generated name
            // for the first FK below (`..._video_session_participant_id_foreign`) exceeds MySQL's
            // 64-character identifier limit given this table's own long name.
            $table->foreignIdFor(VideoSessionParticipant::class)->constrained(indexName: 'vssg_participant_fk');
            $table->foreignIdFor(User::class, 'granted_by_user_id')->constrained('users', indexName: 'vssg_granted_by_fk');
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignIdFor(User::class, 'revoked_by_user_id')->nullable()->constrained('users', indexName: 'vssg_revoked_by_fk');
            $table->enum('revocation_reason', SpeakingPermissionRevocationReasonEnum::values())->nullable();
            // TT-3.2f-h's own idle-expiry sweep: the one narrow mutable field on this otherwise
            // append-only table, updated in place ONLY while the row is still open
            // (revoked_at IS NULL) -- same category exception video_session_participants.left_at
            // already gets on its own otherwise-audit-only table. Never meaningful once revoked.
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            // The join-time "is this participant currently granted?" lookup. Explicit short name
            // for the same 64-character-limit reason as the FKs above.
            $table->index(['video_session_participant_id', 'revoked_at'], 'vssg_participant_revoked_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_session_speaking_grants');
    }
};
