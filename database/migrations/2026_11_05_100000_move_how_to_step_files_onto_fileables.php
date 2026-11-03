<?php

use App\Models\HowToStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-194: a how-to step's file moves from how_to_steps.file_id onto the shared, tagged fileables
// pivot (tag 'step'), like every other single-file slot since TT-10. Existing links are copied first,
// so no step loses its file; down() copies them back before dropping the pivot rows.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('how_to_steps')->whereNotNull('file_id')->orderBy('id')
            ->each(function ($step) {
                DB::table('fileables')->updateOrInsert(
                    ['fileable_type' => HowToStep::class, 'fileable_id' => $step->id, 'tag' => 'step'],
                    ['file_id' => $step->file_id, 'created_at' => now(), 'updated_at' => now()],
                );
            });

        Schema::table('how_to_steps', function (Blueprint $table) {
            $table->dropColumn('file_id');
        });
    }

    public function down(): void
    {
        Schema::table('how_to_steps', function (Blueprint $table) {
            $table->unsignedBigInteger('file_id')->nullable()->after('position');
        });

        DB::table('fileables')->where('fileable_type', HowToStep::class)->where('tag', 'step')->orderBy('id')
            ->each(function ($row) {
                DB::table('how_to_steps')->where('id', $row->fileable_id)->update(['file_id' => $row->file_id]);
            });

        DB::table('fileables')->where('fileable_type', HowToStep::class)->where('tag', 'step')->delete();
    }
};
