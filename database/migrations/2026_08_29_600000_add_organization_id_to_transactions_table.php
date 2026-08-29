<?php

use App\Models\Organization;
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
        Schema::table('transactions', function (Blueprint $table) {
            // Null for a personally-paid transaction. `nullOnDelete` (not `cascadeOnDelete` or
            // `restrictOnDelete`) since `Organization` is soft-deletable and a Transaction must
            // remain a permanent historical record regardless of what later happens to the org.
            $table->foreignIdFor(Organization::class)->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Organization::class);
        });
    }
};
