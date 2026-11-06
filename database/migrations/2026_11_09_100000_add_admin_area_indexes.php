<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-387: indexes for the admin area's queries that had none (checked with SHOW INDEX first) --
// the requests-to-resolve queue and its counts (Request::scopeAwaitingAdmin(): status, type, and
// a null to_id), and the platform stats' status and date filters. Sessions get start_time on its own
// too: the per-period stats filter by date across every status. Each one is skipped when it is
// already there (up) or already gone (down), so a partly applied run can be finished or undone.
return new class extends Migration
{
    private const INDEXES = [
        'requests' => [['status', 'type', 'to_id'], 'requests_status_type_to_id_index'],
        'transactions' => [['status', 'created_at'], 'transactions_status_created_at_index'],
        'sessions' => [['status', 'start_time'], 'sessions_status_start_time_index'],
        'sessions_start_time' => [['start_time'], 'sessions_start_time_index', 'sessions'],
        'counsellor_payouts' => [['status', 'created_at'], 'counsellor_payouts_status_created_at_index'],
        'counsellor_earnings' => [['status', 'created_at'], 'counsellor_earnings_status_created_at_index'],
        'refunds' => [['status', 'created_at'], 'refunds_status_created_at_index'],
        'organization_invoices' => [['status', 'created_at'], 'organization_invoices_status_created_at_index'],
        'users' => [['created_at'], 'users_created_at_index'],
        'video_sessions' => [['started_at'], 'video_sessions_started_at_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $key => $index) {
            [$columns, $name] = $index;
            $table = $index[2] ?? $key;

            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $key => $index) {
            $name = $index[1];
            $table = $index[2] ?? $key;

            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
