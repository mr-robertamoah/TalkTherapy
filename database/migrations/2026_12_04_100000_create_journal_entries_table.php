<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-36 (TT-5.1): a person's private journal -- only they can ever read it (no counsellor, guardian, organisation or
// administrator path). Title and content are encrypted at rest (the `encrypted` cast); mediumText because an encrypted
// value of a 10,000-character entry in a non-Latin script can pass TEXT's 64 KB. Deleted for good, never soft-deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->mediumText('content');
            $table->date('written_on');
            $table->timestamps();

            $table->index(['user_id', 'written_on']);
        });
    }

    // The index goes with the table (devops review: dropping it first fails on MySQL -- the foreign key needs it).
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
