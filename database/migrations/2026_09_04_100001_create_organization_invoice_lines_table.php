<?php

use App\Models\Counsellor;
use App\Models\OrganizationInvoice;
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
        // TT-7.3b-e/SCRUM-236: one row per retainer-covered Session that actually occurred (held)
        // -- written by a new hook in ChangeSessionStatusAction's transition to `held`, at the
        // exact moment the billable clinical event happens, not lazily recomputed at settlement
        // time. This is deliberate (architect decision): the gap between a session occurring and
        // month-end settlement can be weeks, unlike GenerateCounsellorEarningsAction's own
        // same-request recomputation window for pay-per-use -- locking the amount in here avoids
        // that class of drift entirely rather than accepting and merely logging it.
        Schema::create('organization_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(OrganizationInvoice::class)->constrained()->cascadeOnDelete();
            // Unique, not just indexed -- the hook's own idempotency guard (architect finding):
            // ChangeSessionStatusAction's `held` branch is not guaranteed to fire only once per
            // session (a replayed confirm, a retried request), so line-creation must be
            // findOrCreate-on-session_id, backed by this DB-level constraint, never a bare
            // create().
            $table->foreignIdFor(Session::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Counsellor::class)->constrained();
            // Minor units, same convention as counsellor_earnings -- named net_amount/fee_amount
            // (not share_amount/gross) to match that table's own columns exactly, since every
            // line here flows 1:1 into a CounsellorEarning row once its parent invoice settles.
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('fee_amount');
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_invoice_lines');
    }
};
