<?php

use App\DTOs\CreateLicenseDTO;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Http\Resources\LicenseResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\User;
use App\Services\LicenseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// SCRUM-300: counsellor verification documents (national ID scans, professional licenses) are
// reachable ONLY through this authorized route -- never a public URL.

function aLicenseWithPrivateDocument(Counsellor $counsellor): array
{
    Storage::fake('license_documents');

    $license = License::factory()->create([
        'number' => 'GHA-123',
        'licensing_authority_id' => LicensingAuthority::factory()->create([
            'name' => 'National ID',
            'type' => LicensingAuthorityTypeEnum::values()[0],
            'license_type' => LicensingTypeEnum::values()[0],
        ])->id,
        'for_type' => Counsellor::class,
        'for_id' => $counsellor->id,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
    ]);

    Storage::disk('license_documents')->putFileAs('licenses', UploadedFile::fake()->image('national-id.jpg'), 'national-id.jpg');
    $file = File::factory()->create([
        'name' => 'national-id.jpg',
        'path' => 'licenses',
        'storage' => 'license_documents',
        'mime' => 'image/jpeg',
    ]);
    $license->files()->attach($file->id);

    return [$license, $file];
}

function aCounsellorForLicenseDocuments(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('an admin can view a counsellor\'s license document', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$license, $file] = aLicenseWithPrivateDocument(aCounsellorForLicenseDocuments());

    $this->actingAs($admin)->get(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('the counsellor the license belongs to can view their own document', function () {
    $counsellor = aCounsellorForLicenseDocuments();
    [$license, $file] = aLicenseWithPrivateDocument($counsellor);

    $this->actingAs($counsellor->user)->get(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
        ->assertSuccessful();
});

// A uniform 404 for every refusal -- see EnsureUserCanViewLicenseDocumentAction.
test('another counsellor or an unrelated user gets a 404', function () {
    [$license, $file] = aLicenseWithPrivateDocument(aCounsellorForLicenseDocuments());
    $otherCounsellor = aCounsellorForLicenseDocuments();
    $unrelatedUser = User::factory()->create();

    foreach ([$otherCounsellor->user, $unrelatedUser] as $viewer) {
        $this->actingAs($viewer)->get(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
            ->assertStatus(404)
            ->assertJsonPath('error', 'Document not found.');
    }
});

test('a file that is not attached to the license gets the same 404, even for its owner', function () {
    $counsellor = aCounsellorForLicenseDocuments();
    [$license] = aLicenseWithPrivateDocument($counsellor);
    [, $otherFile] = aLicenseWithPrivateDocument(aCounsellorForLicenseDocuments());

    $this->actingAs($counsellor->user)->get(route('licenses.documents.show', ['license' => $license->id, 'file' => $otherFile->id]))
        ->assertStatus(404)
        ->assertJsonPath('error', 'Document not found.');
});

test('a guest cannot view a license document', function () {
    [$license, $file] = aLicenseWithPrivateDocument(aCounsellorForLicenseDocuments());

    $this->getJson(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
        ->assertUnauthorized();
});

test('a license document never resolves a public URL', function () {
    [, $file] = aLicenseWithPrivateDocument(aCounsellorForLicenseDocuments());

    expect(fn () => $file->url)->toThrow(RuntimeException::class);
});

test('the license resource points at the authorized route, not a public URL', function () {
    [$license, $file] = aLicenseWithPrivateDocument(aCounsellorForLicenseDocuments());

    $rendered = (new LicenseResource($license->fresh()))->toArray(request());

    expect($rendered['file'][0]['url'])->toBe(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
        ->and($rendered['file'][0]['url'])->not->toContain('/storage/');
});

test('a newly uploaded license document is stored on the private disk', function () {
    Storage::fake('license_documents');
    Storage::fake('public');
    $counsellor = aCounsellorForLicenseDocuments();
    $authority = LicensingAuthority::factory()->create([
        'name' => 'Medical Council',
        'type' => LicensingAuthorityTypeEnum::values()[0],
        'license_type' => LicensingTypeEnum::values()[0],
    ]);

    $license = LicenseService::new()->createLicense(CreateLicenseDTO::new()->fromArray([
        'addedby' => $counsellor,
        'for' => $counsellor,
        'file' => UploadedFile::fake()->image('license.jpg'),
        'number' => 'MC-1',
        'licensingAuthority' => $authority,
    ]));

    $file = $license->files->first();
    expect($file->storage)->toBe('license_documents')
        ->and(Storage::disk('license_documents')->exists($file->diskPath()))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

// Security review: route-model binding's own 404 would otherwise tell an existing id apart.
test('a nonexistent license or file id gets the same 404 body as a refusal', function () {
    $counsellor = aCounsellorForLicenseDocuments();
    [$license, $file] = aLicenseWithPrivateDocument($counsellor);

    foreach ([['license' => 999999, 'file' => $file->id], ['license' => $license->id, 'file' => 999999]] as $parameters) {
        $this->actingAs($counsellor->user)->getJson(route('licenses.documents.show', $parameters))
            ->assertStatus(404)
            ->assertExactJson(['status' => false, 'error' => 'Document not found.']);
    }
});

// Security review: never the uploader's claimed type, never inline unless it really is an image.
test('a document is served with a server-detected type and hardened headers', function () {
    $counsellor = aCounsellorForLicenseDocuments();
    [$license, $file] = aLicenseWithPrivateDocument($counsellor);
    $file->update(['mime' => 'text/html']);

    $this->actingAs($counsellor->user)->get(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
});

test('a non-image document is forced to download as an opaque type', function () {
    $counsellor = aCounsellorForLicenseDocuments();
    [$license, $file] = aLicenseWithPrivateDocument($counsellor);
    Storage::disk('license_documents')->put($file->diskPath(), '<html><script>alert(1)</script></html>');

    $response = $this->actingAs($counsellor->user)->get(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]));

    $response->assertSuccessful()->assertHeader('Content-Type', 'application/octet-stream');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

test('a file on any other disk is never streamed under license rules', function () {
    $counsellor = aCounsellorForLicenseDocuments();
    [$license, $file] = aLicenseWithPrivateDocument($counsellor);
    $file->update(['storage' => 'identity_documents']);

    $this->actingAs($counsellor->user)->getJson(route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]))
        ->assertStatus(404);
});

test('verification uploads only accept image or PDF identity scans', function () {
    $counsellor = aCounsellorForLicenseDocuments();

    $this->actingAs($counsellor->user)
        ->postJson(route('counsellor.verify', ['counsellorId' => $counsellor->id]), [
            'nationalIdFile' => UploadedFile::fake()->create('evil.html', 1, 'text/html'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('nationalIdFile');
});
