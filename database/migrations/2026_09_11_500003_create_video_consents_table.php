<?php

use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\User;
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
        // TT-3.1e-a/SCRUM-280: one table for both consent modes -- a grant's own consentable
        // (Therapy for PER_THERAPY, Session for PER_SESSION) is structurally identical either
        // way, so splitting them into two tables would just duplicate every column and every
        // enforcement query for no behavioral gain. See documentation/decision-log.md's
        // 2026-09-11 entry for the full architect reasoning.
        //
        // Rows are NEVER deleted. Revocation sets revoked_at/revoked_by_guardian_id/
        // revocation_reason on the EXISTING row -- deliberately NOT VideoSessionParticipant's own
        // "fresh row per cycle" precedent (that fits a participant who can cycle in/out many
        // times per session; a consent grant has exactly one lifecycle at a time per scope). A
        // scope re-granted after a revocation gets a brand-new row, preserving the full
        // grant -> revoke -> re-grant history per scope. "Currently valid" is always
        // whereNull('revoked_at') on the latest row for a scope, mirroring
        // VideoSession::hasActiveParticipant()'s own "derived from latest row" pattern.
        Schema::create('video_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class, 'ward_id');
            $table->foreignIdFor(User::class, 'guardian_id');
            // Columns defined manually (matching morphs()'s own column types) rather than via
            // $table->morphs('consentable') -- that helper adds its own 2-column index, redundant
            // with the composite (consentable_type, consentable_id, revoked_at) index below (a
            // 3-column index already serves any query the 2-column one would).
            $table->unsignedBigInteger('consentable_id');
            $table->string('consentable_type');
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignIdFor(User::class, 'revoked_by_guardian_id')->nullable();
            // Nullable, not just absent-when-not-revoked: a Guardianship-deletion cascade
            // (TT-3.1e-c) revokes on the guardian's behalf, with no acting guardian id of its
            // own to attribute the action to (revoked_by_guardian_id stays null in that case).
            $table->enum('revocation_reason', VideoConsentRevocationReasonEnum::values())->nullable();
            $table->timestamps();

            // TT-3.1e-d's own join-hot-path enforcement query: does a non-revoked grant exist
            // for this exact scope (Therapy or Session)? One indexed lookup, no N+1.
            $table->index(['consentable_type', 'consentable_id', 'revoked_at']);
            // TT-3.1e-e's own reminder-job query: which of this ward's grants are still
            // outstanding (never granted) or otherwise not currently valid?
            $table->index(['ward_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_consents');
    }
};
