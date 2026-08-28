<?php

use App\Enums\RequestTypeEnum;
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
        Schema::table('requests', function (Blueprint $table) {
            // SCRUM-146 (TT-6.4c): generic columns, not compensation-specific -- usable by any
            // future request type that needs an expiry/round-tracking mechanism, not just this one.
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('round')->nullable();
        });

        // `type` is a native enum column -- adding organizationCounsellorCompensationChange to
        // RequestTypeEnum requires re-applying the full current value list here, the same way
        // 2024_05_25_081451_change_column_type_on_requests_table.php originally did. Without this,
        // a real (non-migrate:fresh) database's column would reject the new value at the DB level.
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('type', RequestTypeEnum::values())->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'round']);
        });
    }
};
