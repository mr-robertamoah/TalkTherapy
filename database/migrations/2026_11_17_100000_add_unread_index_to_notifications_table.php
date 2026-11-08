<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-39: the notification center counts a person's unread notifications on every page (the bell) --
// an index for exactly that. Not covering: the count still reads the rows to leave hidden types out, and the
// list sorts one person's rows by created_at -- fine at per-person volumes (TT-5.4d prunes them). It makes the
// morphs() index on (notifiable_type, notifiable_id) redundant; that one is kept for now so this stays a
// trivial rollback (devops review) -- drop it once this one is proven in production.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_unread_index');
        });
    }
};
