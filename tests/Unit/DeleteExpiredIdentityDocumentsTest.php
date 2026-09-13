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

function anIdentityDocumentRequest(string $status, ?Carbon $updatedAt = null): array
{
    Storage::fake('identity_documents');
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

    Storage::disk('identity_documents')->put('doc.jpg', 'contents');
    $file = File::factory()->create(['name' => 'doc.jpg', 'path' => '', 'storage' => 'identity_documents']);
    $request->files()->attach($file->id, ['tag' => 'front-id']);

    return [$request, $file];
}

test('a decided request older than the retention window has its document deleted', function () {
    config(['identity_verification.document_retention_days' => 30]);
    [$request, $file] = anIdentityDocumentRequest(RequestStatusEnum::accepted->value, now()->subDays(31));

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertMissing('doc.jpg');
    expect(File::query()->find($file->id))->toBeNull();
    expect($request->files()->count())->toBe(0);
});

test('a decided request within the retention window is left untouched', function () {
    config(['identity_verification.document_retention_days' => 30]);
    [$request, $file] = anIdentityDocumentRequest(RequestStatusEnum::accepted->value, now()->subDays(5));

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertExists('doc.jpg');
    expect(File::query()->find($file->id))->not->toBeNull();
    expect($request->files()->count())->toBe(1);
});

test('a still-pending request is never swept regardless of age', function () {
    config(['identity_verification.document_retention_days' => 30]);
    [$request, $file] = anIdentityDocumentRequest(RequestStatusEnum::pending->value, now()->subDays(90));

    AppService::new()->deleteExpiredIdentityDocuments();

    Storage::disk('identity_documents')->assertExists('doc.jpg');
    expect(File::query()->find($file->id))->not->toBeNull();
    expect($request->files()->count())->toBe(1);
});
