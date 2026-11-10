<?php

use App\Actions\HowToStep\CreateHowToStepAction;
use App\Actions\HowToStep\DeleteHowToStepAction;
use App\Actions\HowToStep\UpdateHowToStepAction;
use App\DTOs\CreateHowToDTO;
use App\DTOs\CreateHowToStepDTO;
use App\DTOs\GetHowToDTO;
use App\Models\Administrator;
use App\Models\HowTo;
use App\Models\HowToStep;
use App\Models\User;
use App\Services\HowToService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SCRUM-194: a how-to step's file lives on the tagged fileables pivot (tag 'step'), not a file_id column.

function htsfAdminAndHowTo(): array
{
    $admin = User::factory()->has(Administrator::factory())->create();
    $howTo = HowTo::query()->create(['name' => 'Tour', 'description' => 'd', 'page' => 'Home', 'user_id' => $admin->id]);

    return [$admin, $howTo];
}

function htsfCreateStep(User $admin, HowTo $howTo, ?UploadedFile $file): HowToStep
{
    return CreateHowToStepAction::new()->execute(CreateHowToStepDTO::new()->fromArray([
        'user' => $admin, 'howTo' => $howTo, 'name' => 'Step', 'description' => 'd',
        'position' => 1, 'elementId' => 'el', 'file' => $file,
    ]));
}

beforeEach(fn () => Storage::fake('public'));

test('a new step\'s file is attached through the pivot, tagged step', function () {
    [$admin, $howTo] = htsfAdminAndHowTo();

    $step = htsfCreateStep($admin, $howTo, UploadedFile::fake()->image('one.png'));

    expect($step->fresh()->file)->not->toBeNull()
        ->and(DB::table('fileables')->where('fileable_type', HowToStep::class)->where('fileable_id', $step->id)->value('tag'))->toBe('step');
});

test('a step without a file has none', function () {
    [$admin, $howTo] = htsfAdminAndHowTo();

    expect(htsfCreateStep($admin, $howTo, null)->fresh()->file)->toBeNull();
});

test('replacing a step\'s file leaves exactly one, the new one', function () {
    [$admin, $howTo] = htsfAdminAndHowTo();
    $step = htsfCreateStep($admin, $howTo, UploadedFile::fake()->image('one.png'));
    $old = $step->fresh()->file;

    $updated = UpdateHowToStepAction::new()->execute(CreateHowToStepDTO::new()->fromArray([
        'user' => $admin, 'howToStep' => $step->fresh(), 'file' => UploadedFile::fake()->image('two.png'),
    ]));

    expect($updated->stepFile)->toHaveCount(1)
        ->and($updated->file->id)->not->toBe($old->id);
    Storage::disk($old->storage)->assertMissing($old->diskPath());
});

test('deleting a step removes its pivot row', function () {
    [$admin, $howTo] = htsfAdminAndHowTo();
    $step = htsfCreateStep($admin, $howTo, UploadedFile::fake()->image('one.png'));

    $file = $step->fresh()->file;
    DeleteHowToStepAction::new()->execute(CreateHowToStepDTO::new()->fromArray(['user' => $admin, 'howToStep' => $step->fresh()]));

    expect(HowToStep::query()->find($step->id))->toBeNull()
        ->and(DB::table('fileables')->where('fileable_type', HowToStep::class)->where('fileable_id', $step->id)->exists())->toBeFalse();
    Storage::disk($file->storage)->assertMissing($file->diskPath());
});

test('security review: editing one how-to can\'t touch another how-to\'s steps', function () {
    [$admin, $howTo] = htsfAdminAndHowTo();
    $other = HowTo::query()->create(['name' => 'Other', 'description' => 'd', 'page' => 'Profile', 'user_id' => $admin->id]);
    $foreignStep = htsfCreateStep($admin, $other, UploadedFile::fake()->image('theirs.png'));

    expect(fn () => HowToService::new()->updateHowTo(CreateHowToDTO::new()->fromArray([
        'user' => $admin, 'howTo' => $howTo,
        'howToSteps' => [], 'addedHowToSteps' => [],
        'deletedHowToSteps' => [['id' => $foreignStep->id]],
    ])))->toThrow(ModelNotFoundException::class);

    expect(HowToStep::query()->find($foreignStep->id))->not->toBeNull()
        ->and($foreignStep->fresh()->file)->not->toBeNull();
});

test('listing how-tos loads every step\'s file in one query, not one per step', function () {
    [$admin, $howTo] = htsfAdminAndHowTo();
    foreach (range(1, 3) as $position) {
        $step = htsfCreateStep($admin, $howTo, UploadedFile::fake()->image("s{$position}.png"));
        $step->update(['position' => $position, 'element_id' => "el{$position}"]);
    }

    DB::enableQueryLog();
    HowToService::new()->getHowTos(GetHowToDTO::new()->fromArray(['pageLike' => 'Home']))->toResponse(request());
    $fileQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'fileables'))->count();

    expect($fileQueries)->toBe(1);
});
