<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-338: when a license's uploaded documents are deleted under the retention policy, the
    // License row itself is kept (it's the record of what was checked) and this says when and that
    // the files are gone on purpose, so the admin UI can say so instead of showing a blank.
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->timestamp('documents_removed_at')->nullable()->after('validated');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('documents_removed_at');
        });
    }
};
