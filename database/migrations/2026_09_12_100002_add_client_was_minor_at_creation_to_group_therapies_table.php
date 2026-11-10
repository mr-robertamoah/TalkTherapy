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
        Schema::table('group_therapies', function (Blueprint $table) {
            // TT-4.10a/SCRUM-290: identical column and reasoning to therapies.client_was_minor_at_creation
            // (see that migration's own comment) -- GENUINELY nullable here, unlike its Therapy
            // sibling: CreateGroupTherapyAction's own addedby can be either a User or a Counsellor
            // ($dto->counsellor ?: $dto->user), and a Counsellor-created group has no single
            // "client" this column applies to at all. Left null in that case, never coerced to
            // false -- null means "not applicable," false means "was an adult at creation."
            $table->boolean('client_was_minor_at_creation')->nullable()->after('payment_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_therapies', function (Blueprint $table) {
            $table->dropColumn('client_was_minor_at_creation');
        });
    }
};
