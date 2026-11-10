<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\File;
use App\Models\Request;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// TT-4.11a/SCRUM-302: the real HTTP retrieval route -- the ONLY way an identity-verification
// document is ever reachable. Never a public asset() URL (see the pre-existing gap this
// deliberately avoids, SCRUM-300, and config/filesystems.php's 'identity_documents' disk comment).

function anUploadedIdentityDocumentRequest(User $for): array
{
    Storage::fake('identity_documents');

    // `Request::files()` is generic and type-agnostic -- TT-4.11a adds no RequestTypeEnum case of
    // its own (that's TT-4.11b's job), so any existing, DB-check-constraint-valid type works
    // equally well here; `guardianship` carries no special meaning for this test.
    $request = Request::factory()->for($for, 'from')->for($for, 'for')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'data' => [],
        'status' => RequestStatusEnum::pending->value,
    ]);

    $uploaded = UploadedFile::fake()->image('id-front.jpg');
    Storage::disk('identity_documents')->putFileAs('', $uploaded, 'id-front.jpg');

    $file = File::factory()->create([
        'name' => 'id-front.jpg',
        'path' => '',
        'storage' => 'identity_documents',
        'mime' => 'image/jpeg',
    ]);
    $request->files()->attach($file->id, ['tag' => 'front-id']);

    return [$request, $file];
}

test('an admin can retrieve an identity document via the real HTTP endpoint', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $submitter = User::factory()->create();
    [$request, $file] = anUploadedIdentityDocumentRequest($submitter);

    $response = $this->actingAs($admin)->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]));

    $response->assertSuccessful();
});

test('the submitter can retrieve their own uploaded identity document', function () {
    $submitter = User::factory()->create();
    [$request, $file] = anUploadedIdentityDocumentRequest($submitter);

    $response = $this->actingAs($submitter)->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]));

    $response->assertSuccessful();
});

// Security-review finding: a uniform 404 regardless of WHY access is refused (unauthorized vs.
// the file simply not being attached to this request) -- an unrelated caller must never be able
// to distinguish "this pair doesn't exist" from "it does, but you can't see it."
test('an unrelated user cannot retrieve someone else\'s identity document', function () {
    $submitter = User::factory()->create();
    $unrelatedUser = User::factory()->create();
    [$request, $file] = anUploadedIdentityDocumentRequest($submitter);

    $response = $this->actingAs($unrelatedUser)->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]));

    $response->assertStatus(404);
});

test('a guest cannot retrieve an identity document at all', function () {
    $submitter = User::factory()->create();
    [$request, $file] = anUploadedIdentityDocumentRequest($submitter);

    $response = $this->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]));

    $response->assertRedirect(route('login'));
});

// Security-review hardening: getUrlFor() (File::url) refuses outright for an identity-document
// file, rather than silently producing a working public link the way it otherwise would for
// every other disk (that's the pre-existing gap filed separately as SCRUM-300, not fixed here).
// Defense-in-depth against a future careless ->url call bypassing the dedicated route above.
test('File::url refuses to resolve an identity-verification document', function () {
    $submitter = User::factory()->create();
    [, $file] = anUploadedIdentityDocumentRequest($submitter);

    expect(fn () => $file->url)->toThrow(RuntimeException::class);
});
