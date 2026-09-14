<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\File;
use App\Models\Request;
use App\Models\User;
use App\Services\AppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

// TT-4.11a/SCRUM-302: an identity document is only ever swept once its owning Request has been
// DECIDED (not PENDING) and the configurable retention window (30 days by default) has passed
// since that decision -- never for a still-pending request, regardless of age.

function anIdentityDocumentRequest(string $status, ?Carbon $updatedAt = null, string $filename = 'doc.jpg'): array
{
    $for = User::factory()->create();

    // `Request::files()` is generic and type-agnostic -- TT-4.11a adds no RequestTypeEnum case of
    // its own (that's TT-4.11b's job), so any existing, DB-check-constraint-valid type works
    // equally well here; `guardianship` carries no special meaning for this test.
    $request = Request::factory()->for($for, 'from')->for($for, 'for')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'data' => [],
        'status' => $status,
    ]);

    if ($updatedAt) {
        // `updated_at` isn't in Request's own $fillable, and Eloquent would otherwise overwrite
        // a manually-set value back to "now" on save -- forceFill() bypasses the fillable guard,
        // and disabling timestamps stops save() from re-stamping it automatically afterward.
        $request->timestamps = false;
        $request->forceFill(['updated_at' => $updatedAt])->save();
    }

    Storage::disk('identity_documents')->put($filename, 'contents');
    $file = File::factory()->create(['name' => $filename, 'path' => '', 'storage' => 'identity_documents']);
    $request->files()->attach($file->id, ['tag' => 'front-id']);

    return [$request, $file];
}

test('a decided request older than the retention window has its document deleted', function () {
    Storage::fake('identity_documents');
    config(['identity_verification.document_retention_days' => 30]);
    [$request, $file] = anIdentityDocumentRequest(RequestStatusEnum::accepted->value, now()->subDays(31));

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertMissing('doc.jpg');
    expect(File::query()->find($file->id))->toBeNull();
    expect($request->files()->count())->toBe(0);
});

test('a decided request within the retention window is left untouched', function () {
    Storage::fake('identity_documents');
    config(['identity_verification.document_retention_days' => 30]);
    [$request, $file] = anIdentityDocumentRequest(RequestStatusEnum::accepted->value, now()->subDays(5));

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertExists('doc.jpg');
    expect(File::query()->find($file->id))->not->toBeNull();
    expect($request->files()->count())->toBe(1);
});

test('a still-pending request is never swept regardless of age', function () {
    Storage::fake('identity_documents');
    config(['identity_verification.document_retention_days' => 30]);
    [$request, $file] = anIdentityDocumentRequest(RequestStatusEnum::pending->value, now()->subDays(90));

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertExists('doc.jpg');
    expect(File::query()->find($file->id))->not->toBeNull();
    expect($request->files()->count())->toBe(1);
});

// TT-4.11e/SCRUM-306 closeout: every other test in this file happens to set the retention window
// to its own default value (30), which would pass identically even if the config were never
// actually read (a hardcoded 30 would look the same). This proves the window is genuinely
// configurable -- a non-default value of 7 days is respected on both sides of its own boundary.
test('a shorter, non-default retention window is genuinely respected, not hardcoded to the default', function () {
    Storage::fake('identity_documents');
    config(['identity_verification.document_retention_days' => 7]);
    [$requestJustPast, $fileJustPast] = anIdentityDocumentRequest(RequestStatusEnum::accepted->value, now()->subDays(8), 'just-past.jpg');
    [$requestNotYet, $fileNotYet] = anIdentityDocumentRequest(RequestStatusEnum::accepted->value, now()->subDays(6), 'not-yet.jpg');

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertMissing('just-past.jpg');
    expect(File::query()->find($fileJustPast->id))->toBeNull();
    expect($requestJustPast->files()->count())->toBe(0);

    Storage::disk('identity_documents')->assertExists('not-yet.jpg');
    expect(File::query()->find($fileNotYet->id))->not->toBeNull();
    expect($requestNotYet->files()->count())->toBe(1);
});
