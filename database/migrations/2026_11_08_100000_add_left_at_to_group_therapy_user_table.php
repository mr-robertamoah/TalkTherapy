<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-73: leaving a group keeps the membership row and stamps it, so report history, the
    // dob-change rule and a rejoin's original join date still see it. GroupTherapy::users() is the
    // current members only (left_at null); allMemberships() is everyone who was ever one.
    public function up(): void
    {
        Schema::table('group_therapy_user', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('was_minor_at_join');
            $table->index(['group_therapy_id', 'left_at'], 'gtu_group_left_idx');
            // User::groupTherapies() (a user's current groups) filters on left_at too.
            $table->index(['user_id', 'left_at'], 'gtu_user_left_idx');
        });
    }

    public function down(): void
    {
        Schema::table('group_therapy_user', function (Blueprint $table) {
            $table->dropIndex('gtu_group_left_idx');
            $table->dropIndex('gtu_user_left_idx');
            $table->dropColumn('left_at');
        });
    }
};
