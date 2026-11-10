<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-354: whether a comment's author was one of the therapy's anonymous people when they wrote it,
// so a later change (leaving the group, anonymity switched off) never unmasks the comment.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('anonymous')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('anonymous');
        });
    }
};
