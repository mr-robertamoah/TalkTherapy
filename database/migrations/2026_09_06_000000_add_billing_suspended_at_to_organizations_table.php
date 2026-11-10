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
        // TT-7.3b-f2/SCRUM-238: current-state flag, mirroring `verified_at`'s own precedent --
        // not a new append-only history table, since only the CURRENT suspension standing is
        // ever read at the access-gate call site. Single writer (this ticket's own scope):
        // UpdateOrganizationInvoiceStatusAction, on a retainer invoice settlement failure.
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('billing_suspended_at')->nullable();
            $table->text('billing_suspension_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['billing_suspended_at', 'billing_suspension_reason']);
        });
    }
};
