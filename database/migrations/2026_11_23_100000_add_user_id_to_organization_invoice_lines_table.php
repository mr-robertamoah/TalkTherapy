<?php

use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-420: a held group session is billed once per member a retainer covers, and once per counsellor
    // running it -- so a line is one (session, counsellor, member), no longer one per session. `user_id` is the
    // covered member; existing lines (one-to-one therapies) are filled in with the therapy's client.
    public function up(): void
    {
        Schema::table('organization_invoice_lines', function (Blueprint $table) {
            $table->foreignIdFor(User::class)->nullable()->after('session_id')->constrained()->nullOnDelete();
        });

        // A correlated subquery, not an UPDATE ... JOIN: the same statement runs on MySQL and the tests' SQLite.
        DB::update(
            'update organization_invoice_lines set user_id = (
                select therapies.addedby_id from sessions
                inner join therapies on therapies.id = sessions.for_id
                where sessions.id = organization_invoice_lines.session_id
                    and sessions.for_type = ? and therapies.addedby_type = ?
            ) where user_id is null',
            [Therapy::class, User::class]
        );

        Schema::table('organization_invoice_lines', function (Blueprint $table) {
            // Added before the old one goes: it starts with session_id, so the foreign key keeps an index.
            $table->unique(['session_id', 'counsellor_id', 'user_id'], 'organization_invoice_lines_session_counsellor_user_unique');
            $table->dropUnique(['session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('organization_invoice_lines', function (Blueprint $table) {
            // Fails if a group session already has several lines -- delete those first to roll back.
            $table->unique(['session_id']);
            $table->dropUnique('organization_invoice_lines_session_counsellor_user_unique');
            $table->dropConstrainedForeignIdFor(User::class);
        });
    }
};
