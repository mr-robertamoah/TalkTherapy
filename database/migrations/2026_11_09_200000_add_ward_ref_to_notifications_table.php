<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-386: the WardRef a notice carries in its data (App\Support\WardRef::KEY), as an indexed
    // generated column, so anonymising a closed account (AnonymiseClosedAccountAction) finds the
    // notices naming them by index instead of a LIKE over every row of the same types. Null for a
    // row whose data isn't valid JSON (json_extract would otherwise fail the write, or this
    // migration on an existing bad row), or that carries no WardRef.
    // MySQL: a stored column -- adding it rebuilds the notifications table. SQLite (the test suite)
    // can't add a stored column to an existing table, so it gets a virtual one, which it can index.
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $column = $table->string('ward_ref')->nullable();

            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $column->virtualAs("case when json_valid(data) then json_extract(data, '$.wardRef') end");
            } else {
                // nullif: json_unquote() turns a JSON null into the string 'null'.
                $column->storedAs("if(json_valid(data), nullif(json_unquote(json_extract(data, '$.wardRef')), 'null'), null)");
            }

            $table->index('ward_ref');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['ward_ref']);
            $table->dropColumn('ward_ref');
        });
    }
};
