<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SCRUM-443: an online session has no place. They used to be stored at 0,0 (and a session switched to online kept its
// old pin); clear them so only in-person sessions carry a location. In-person sessions are untouched.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sessions')->where('type', 'ONLINE')
            ->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('longitude')->orWhereNotNull('landmark'))
            ->update(['latitude' => null, 'longitude' => null, 'landmark' => null]);
    }

    // Not reversible: the cleared values were meaningless (0,0) or a stale place for a session that no longer meets.
    public function down(): void {}
};
