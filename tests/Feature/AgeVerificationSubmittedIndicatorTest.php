<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\User;

// TT-4.11b/SCRUM-303 bug, found during TT-4.11e/SCRUM-306's closeout QA pass:
// AgeVerificationSection.vue's "submit a statement"/"submit another statement" label previously
// depended entirely on local, in-session state (a `submitted` ref initialized to `false` on every
// fresh page load) -- a user with a genuinely still-pending request always saw "submit a
// statement" until they submitted again in that same session, contradicting the actual, durable
// server-side state. Fixed by having ProfileController::show() query for a real pending request
// and pass it as an Inertia prop, the same way `hasPendingRequest` should always reflect reality
// regardless of session history.

test('the profile page reports a pending age-verification request as an Inertia prop', function () {
    $user = User::factory()->create();
    CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasPendingAgeVerification', true));
});

test('the profile page reports no pending age-verification request when none exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasPendingAgeVerification', false));
});

test('the profile page reports no pending age-verification request once it has been decided', function () {
    $user = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));
    $request->update(['status' => RequestStatusEnum::accepted->value]);

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasPendingAgeVerification', false));
});

test('the profile page never reports another user\'s pending age-verification request', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $otherUser, 'to' => null, 'for' => $otherUser,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasPendingAgeVerification', false));
});
