<?php

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
        Schema::table('therapies', function (Blueprint $table) {
            // TT-4.10a/SCRUM-290: captured once, at CreateTherapyAction time, from the therapy's
            // own addedby User's live isAdult() at that exact moment -- see the sibling migration
            // on `guardianship` for the full "why a stable snapshot" reasoning. A DIFFERENT
            // column from that one (not one shared mechanism) -- architect decision, 2026-09-12:
            // a Guardianship row and a Therapy row answer different questions ("is the ward a
            // minor" vs "is THIS THERAPY'S client a minor") and don't necessarily correspond 1:1
            // (a ward can have a Guardianship with no Therapy at all, or a Therapy created before
            // any Guardianship exists). CreateTherapyAction's own single creation path
            // ($createTherapyDTO->user->addedTherapies()->create(...)) always has a real User
            // addedby, so in practice this is never actually null for a Therapy -- nullable here
            // only for consistency with group_therapies' own identical column, where a
            // Counsellor-created group genuinely has no single "client" this applies to.
            $table->boolean('client_was_minor_at_creation')->nullable()->after('payment_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('therapies', function (Blueprint $table) {
            $table->dropColumn('client_was_minor_at_creation');
        });
    }
};
