<?php

use App\Models\File;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// SCRUM-182/TT-10.1: the fileables pivot gained a nullable `tag` column plus a composite unique
// index on (fileable_type, fileable_id, tag). These tests confirm the 4 existing untagged
// consumers (License/Post/Report/Message -- Report used here as a simple representative, all 4
// share the identical morphToMany(File::class, 'fileable', 'fileables') relation) are unaffected,
// and that the new constraint actually enforces at-most-one-row-per-tag for tagged usage.

test('an existing untagged consumer can still attach multiple files to the same model', function () {
    $report = Report::factory()->create();
    $files = File::factory()->count(3)->create();

    $report->files()->attach($files->pluck('id'));

    expect($report->files()->count())->toBe(3);

    DB::table('fileables')
        ->where('fileable_type', Report::class)
        ->where('fileable_id', $report->id)
        ->pluck('tag')
        ->each(fn (?string $tag) => expect($tag)->toBeNull());
});

test('multiple fileables rows for the same model with a null tag do not violate the unique index', function () {
    $report = Report::factory()->create();

    DB::table('fileables')->insert([
        ['file_id' => File::factory()->create()->id, 'fileable_type' => Report::class, 'fileable_id' => $report->id, 'tag' => null],
        ['file_id' => File::factory()->create()->id, 'fileable_type' => Report::class, 'fileable_id' => $report->id, 'tag' => null],
    ]);

    expect(DB::table('fileables')->where('fileable_id', $report->id)->count())->toBe(2);
});

test('the unique index rejects a second row with the same tag for the same model', function () {
    $report = Report::factory()->create();

    DB::table('fileables')->insert([
        'file_id' => File::factory()->create()->id,
        'fileable_type' => Report::class,
        'fileable_id' => $report->id,
        'tag' => 'avatar',
    ]);

    expect(fn () => DB::table('fileables')->insert([
        'file_id' => File::factory()->create()->id,
        'fileable_type' => Report::class,
        'fileable_id' => $report->id,
        'tag' => 'avatar',
    ]))->toThrow(QueryException::class);
});

test('the same tag can be reused across different models without conflict', function () {
    $reports = Report::factory()->count(2)->create();

    foreach ($reports as $report) {
        DB::table('fileables')->insert([
            'file_id' => File::factory()->create()->id,
            'fileable_type' => Report::class,
            'fileable_id' => $report->id,
            'tag' => 'avatar',
        ]);
    }

    expect(DB::table('fileables')->where('tag', 'avatar')->count())->toBe(2);
});
