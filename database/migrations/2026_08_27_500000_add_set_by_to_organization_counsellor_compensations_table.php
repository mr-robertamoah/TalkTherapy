<?php

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
        // Accountability trail (SCRUM-123): who set/renegotiated each row -- only ever an org
        // admin (a User, per EnsureUserCanSetOrganizationCounsellorCompensationAction), so a
        // plain foreign key rather than a polymorphic morph.
        Schema::table('organization_counsellor_compensations', function (Blueprint $table) {
            $table->foreignIdFor(User::class, 'set_by_id')->nullable()->after('organization_counsellor_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_counsellor_compensations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('set_by_id');
        });
    }
};
