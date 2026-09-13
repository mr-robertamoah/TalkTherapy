<?php

use App\Actions\User\SubmitAgeVerificationAction;
use App\DTOs\SubmitAgeVerificationDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\File;
use App\Models\Request;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// TT-4.11b/SCRUM-303: a user's own self-attestation (+ optional document) that their dob is
// accurate. Document upload is deliberately OPTIONAL -- self-attestation alone is always
// sufficient. Always admin-reviewed (null `to`), idempotent (reuses an already-outstanding
// pending request rather than creating a duplicate).

test('submitting with no document creates a pending, admin-addressed request carrying the attestation', function () {
    $user = User::factory()->create();

    $request = SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'I am 25 years old, born in 2001.',
    ]));

    expect($request->type)->toBe(RequestTypeEnum::ageVerification->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->from_id)->toBe($user->id);
    expect($request->for_id)->toBe($user->id);
    expect($request->to_id)->toBeNull();
    expect($request->data['attestation'])->toBe('I am 25 years old, born in 2001.');
    expect($request->identityDocument()->count())->toBe(0);
});

test('submitting with a document stores it on the private identity_documents disk', function () {
    Storage::fake('identity_documents');
    $user = User::factory()->create();

    $request = SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'I am 25 years old, born in 2001.',
        'document' => UploadedFile::fake()->image('id.jpg'),
    ]));

    $file = $request->identityDocument()->first();
    expect($file)->not->toBeNull();
    expect($file->storage)->toBe('identity_documents');
    Storage::disk('identity_documents')->assertExists((strlen($file->path) ? $file->path.'/' : '').$file->name);
});

test('a second submission while the first is still pending reuses it instead of creating a duplicate', function () {
    $user = User::factory()->create();

    SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'first attempt',
    ]));
    SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'second, corrected attempt',
    ]));

    $requests = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->get();
    expect($requests)->toHaveCount(1);
    expect($requests->first()->data['attestation'])->toBe('second, corrected attempt');
});

test('re-submitting with a new document replaces the old one, deleting it from disk', function () {
    Storage::fake('identity_documents');
    $user = User::factory()->create();

    SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'first attempt',
        'document' => UploadedFile::fake()->image('first.jpg'),
    ]));
    $firstFile = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->first()->identityDocument()->first();
    $firstPath = (strlen($firstFile->path) ? $firstFile->path.'/' : '').$firstFile->name;

    $request = SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'second attempt',
        'document' => UploadedFile::fake()->image('second.jpg'),
    ]));

    Storage::disk('identity_documents')->assertMissing($firstPath);
    expect(File::query()->find($firstFile->id))->toBeNull();
    expect($request->identityDocument()->count())->toBe(1);
});
