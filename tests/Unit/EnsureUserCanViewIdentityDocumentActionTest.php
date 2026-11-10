<?php

use App\Actions\User\EnsureUserCanViewIdentityDocumentAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\IdentityDocumentAccessDeniedException;
use App\Models\Administrator;
use App\Models\File;
use App\Models\Request;
use App\Models\User;

// TT-4.11a/SCRUM-302: the one and only authorization gate for viewing an identity-verification
// document -- an admin, or the request's own `for` user (viewing their own upload back).

function aRequestWithAnAttachedIdentityDocument(User $for): array
{
    // `Request::files()` is generic and type-agnostic -- TT-4.11a adds no RequestTypeEnum case of
    // its own (that's TT-4.11b's job), so any existing, DB-check-constraint-valid type works
    // equally well here; `guardianship` carries no special meaning for this test.
    $request = Request::factory()->for($for, 'from')->for($for, 'for')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'data' => [],
        'status' => RequestStatusEnum::pending->value,
    ]);

    $file = File::factory()->create(['storage' => 'identity_documents']);
    $request->files()->attach($file->id, ['tag' => 'front-id']);

    return [$request, $file];
}

test('an admin can view any identity document', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $submitter = User::factory()->create();
    [$request, $file] = aRequestWithAnAttachedIdentityDocument($submitter);

    expect(fn () => EnsureUserCanViewIdentityDocumentAction::new()->execute($admin, $request, $file))
        ->not->toThrow(IdentityDocumentAccessDeniedException::class);
});

test('the request\'s own `for` user can view their own uploaded document', function () {
    $submitter = User::factory()->create();
    [$request, $file] = aRequestWithAnAttachedIdentityDocument($submitter);

    expect(fn () => EnsureUserCanViewIdentityDocumentAction::new()->execute($submitter, $request, $file))
        ->not->toThrow(IdentityDocumentAccessDeniedException::class);
});

test('an unrelated user cannot view someone else\'s identity document', function () {
    $submitter = User::factory()->create();
    $unrelatedUser = User::factory()->create();
    [$request, $file] = aRequestWithAnAttachedIdentityDocument($submitter);

    expect(fn () => EnsureUserCanViewIdentityDocumentAction::new()->execute($unrelatedUser, $request, $file))
        ->toThrow(IdentityDocumentAccessDeniedException::class);
});

test('a file not attached to the specified request is refused even for an admin', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $submitter = User::factory()->create();
    [$request] = aRequestWithAnAttachedIdentityDocument($submitter);
    $unrelatedFile = File::factory()->create(['storage' => 'identity_documents']);

    expect(fn () => EnsureUserCanViewIdentityDocumentAction::new()->execute($admin, $request, $unrelatedFile))
        ->toThrow(IdentityDocumentAccessDeniedException::class);
});
