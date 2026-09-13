<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Request;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// TT-4.11e/SCRUM-306 closeout: SubmitAgeVerificationTest.php and AgeVerificationRequestResponseTest.php
// each independently exercise the submit and respond endpoints, but neither ever chains them
// together for a request that actually has a document attached -- this is the one regression-matrix
// item ("document-upload submission approve/reject") that fell between those two files. Exercises
// the full real-HTTP path: submit with a document -> admin approves/rejects -> document remains
// retrievable ONLY via the authorized route throughout, regardless of the request's final status.

test('a document-attached submission can be approved via the real endpoints end to end', function () {
    Storage::fake('identity_documents');
    $user = User::factory()->create(['dob' => now()->subYears(25)->toDateString()]);
    $admin = User::factory()->has(Administrator::factory())->create();

    $submitResponse = $this->actingAs($user)->postJson(route('api.users.age-verification'), [
        'attestation' => 'I am 25 years old, born in 2001.',
        'document' => UploadedFile::fake()->image('id.jpg'),
    ]);
    $submitResponse->assertSuccessful();

    $request = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->whereFrom($user)->first();
    $file = $request->identityDocument()->first();
    expect($file)->not->toBeNull();

    // The document is retrievable by the admin BEFORE any decision is made.
    $this->actingAs($admin)
        ->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]))
        ->assertSuccessful();

    $respondResponse = $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);
    $respondResponse->assertSuccessful();

    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
    expect($user->fresh()->dob_verified_at)->not->toBeNull();

    // The document is still attached and retrievable AFTER approval -- an accepted decision
    // doesn't itself detach or move the file (only the retention sweep, TT-4.11a, does that,
    // and only after the configurable window elapses).
    expect($request->fresh()->identityDocument()->count())->toBe(1);
    $this->actingAs($admin)
        ->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]))
        ->assertSuccessful();
});

test('a document-attached submission can be rejected via the real endpoints end to end', function () {
    Storage::fake('identity_documents');
    $user = User::factory()->create();
    $admin = User::factory()->has(Administrator::factory())->create();

    $this->actingAs($user)->postJson(route('api.users.age-verification'), [
        'attestation' => 'I am 25 years old, born in 2001.',
        'document' => UploadedFile::fake()->image('id.jpg'),
    ])->assertSuccessful();

    $request = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->whereFrom($user)->first();
    $file = $request->identityDocument()->first();

    $respondResponse = $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'rejected',
    ]);
    $respondResponse->assertSuccessful();

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($user->fresh()->dob_verified_at)->toBeNull();

    // Still attached and retrievable -- rejection doesn't delete the document either; only the
    // retention sweep does, once the retention window has actually elapsed.
    expect($request->fresh()->identityDocument()->count())->toBe(1);
    $this->actingAs($admin)
        ->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]))
        ->assertSuccessful();
});

test('an unrelated user still cannot retrieve the document after the request has been decided', function () {
    Storage::fake('identity_documents');
    $user = User::factory()->create();
    $admin = User::factory()->has(Administrator::factory())->create();
    $unrelatedUser = User::factory()->create();

    $this->actingAs($user)->postJson(route('api.users.age-verification'), [
        'attestation' => 'I am 25 years old, born in 2001.',
        'document' => UploadedFile::fake()->image('id.jpg'),
    ])->assertSuccessful();

    $request = Request::query()->whereType(RequestTypeEnum::ageVerification->value)->whereFrom($user)->first();
    $file = $request->identityDocument()->first();

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ])->assertSuccessful();

    $this->actingAs($unrelatedUser)
        ->get(route('requests.documents.show', ['request' => $request->id, 'file' => $file->id]))
        ->assertStatus(404);
});
