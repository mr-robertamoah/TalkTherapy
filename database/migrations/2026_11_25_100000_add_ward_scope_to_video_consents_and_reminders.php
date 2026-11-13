<?php

use App\Enums\VideoConsentLevelEnum;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-313: a group can have several minors, each with their own guardians' consent on the same group or session,
    // so a grant and a reminder are per ward, no longer per scope alone.
    //  - video_consents.level: watch only, or speak too (SCRUM-329). Every existing grant is SPEAK -- one-to-one
    //    consent has always meant taking full part.
    //  - an index led by ward_id for "this ward's valid grant on this scope", the lookup every check now makes.
    //  - video_consent_reminders.ward_id, unique per (session, ward); existing rows are filled in with the therapy's
    //    client (reminders have only ever been sent for one-to-one therapies).
    public function up(): void
    {
        Schema::table('video_consents', function (Blueprint $table) {
            $table->enum('level', VideoConsentLevelEnum::values())->default(VideoConsentLevelEnum::speak->value)->after('consentable_type');
            $table->index(['ward_id', 'consentable_type', 'consentable_id', 'revoked_at'], 'video_consents_ward_scope_valid_idx');
        });

        Schema::table('video_consent_reminders', function (Blueprint $table) {
            $table->foreignIdFor(User::class, 'ward_id')->nullable()->after('session_id')->constrained('users')->cascadeOnDelete();
        });

        // A correlated subquery, not an UPDATE ... JOIN: the same statement runs on MySQL and the tests' SQLite.
        DB::update(
            'update video_consent_reminders set ward_id = (
                select therapies.addedby_id from sessions
                inner join therapies on therapies.id = sessions.for_id
                where sessions.id = video_consent_reminders.session_id
                    and sessions.for_type = ? and therapies.addedby_type = ?
            ) where ward_id is null',
            [Therapy::class, User::class]
        );

        Schema::table('video_consent_reminders', function (Blueprint $table) {
            // Added before the old one goes: it starts with session_id, so the foreign key keeps an index.
            $table->unique(['session_id', 'ward_id'], 'video_consent_reminders_session_ward_unique');
            $table->dropUnique(['session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('video_consent_reminders', function (Blueprint $table) {
            // Fails if a session already has reminders for several wards -- delete those first to roll back.
            $table->unique(['session_id']);
            $table->dropUnique('video_consent_reminders_session_ward_unique');
            $table->dropConstrainedForeignId('ward_id');
        });

        Schema::table('video_consents', function (Blueprint $table) {
            $table->dropIndex('video_consents_ward_scope_valid_idx');
            $table->dropColumn('level');
        });
    }
};
