<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\User;

// TT-4.11b/SCRUM-303 bug, found during TT-4.11e/SCRUM-306's closeout QA pass (two rounds):
// AgeVerificationSection.vue's "submit a statement"/"submit another statement" label previously
// depended entirely on local, in-session state (a `submitted` ref initialized to `false` on every
// fresh page load) -- a user with a genuinely already-submitted request always saw "submit a
// statement" until they submitted again in that same session, contradicting the actual, durable
// server-side state. The FIRST fix scoped this to "has a PENDING request" -- qa-engineer caught
// that this reverted the label back to "submit a statement" the instant an admin decided the
// request, the same misleading-label bug shifted to a different transition. Fixed by having
// ProfileController::show() ask "has this user ever submitted one at all" (any status), via
// HasSubmittedAgeVerificationAction -- "submit another statement" is accurate regardless of
// whether the prior submission is pending, accepted, or rejected.

test('the profile page reports true when the user has a pending age-verification request', function () {
    $user = User::factory()->create();
    CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasSubmittedAgeVerification', true));
});

test('the profile page reports false when the user has never submitted an age-verification request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasSubmittedAgeVerification', false));
});

test('the profile page still reports true once the request has been accepted', function () {
    $user = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));
    $request->update(['status' => RequestStatusEnum::accepted->value]);

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasSubmittedAgeVerification', true));
});

test('the profile page still reports true once the request has been rejected', function () {
    $user = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));
    $request->update(['status' => RequestStatusEnum::rejected->value]);

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasSubmittedAgeVerification', true));
});

test('the profile page never reports another user\'s submission', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $otherUser, 'to' => null, 'for' => $otherUser,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('hasSubmittedAgeVerification', false));
});
