<?php

use App\Enums\RequestTypeEnum;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\User;

// TT-4.10c/SCRUM-292: ProfileController::update() must route a boundary-crossing dob edit through
// EnsureDobChangeIsAllowedAction -- deferring the dob field specifically while everything else in
// the same submission still saves immediately.

test('a user with no qualifying relationship has their dob updated directly', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'firstName' => $user->firstName,
        'lastName' => $user->lastName,
        'dob' => now()->subYears(30)->toDateString(),
    ]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->dob->toDateString())->toBe(now()->subYears(30)->toDateString());
    expect(Request::query()->whereType(RequestTypeEnum::dobChange->value)->count())->toBe(0);
});

test('a qualifying user\'s boundary-crossing dob edit is deferred, but other submitted fields still save', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString(), 'firstName' => 'Original']);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    $response = $this->actingAs($minor)->patch(route('profile.update'), [
        'firstName' => 'Updated',
        'lastName' => $minor->lastName,
        'dob' => now()->subYears(30)->toDateString(),
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('dobChangePendingApproval', true);

    $fresh = $minor->fresh();
    expect($fresh->firstName)->toBe('Updated');
    expect($fresh->dob->toDateString())->toBe(now()->subYears(17)->toDateString());

    $request = Request::query()->whereType(RequestTypeEnum::dobChange->value)->first();
    expect($request)->not->toBeNull();
    expect($request->for_id)->toBe($minor->id);
    expect($request->to_id)->toBe($guardian->id);
});

// TT-4.10f/SCRUM-295 regression-matrix: the reverse direction (adult claiming to become a minor)
// was only previously exercised at the Action level (EnsureDobChangeIsAllowedActionTest), never
// through the real HTTP profile.update route -- closing that gap here.
test('a qualifying user\'s adult-to-minor boundary-crossing edit is also deferred via the real endpoint', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id]);

    $response = $this->actingAs($adult)->patch(route('profile.update'), [
        'firstName' => $adult->firstName,
        'lastName' => $adult->lastName,
        'dob' => now()->subYears(10)->toDateString(),
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('dobChangePendingApproval', true);
    expect($adult->fresh()->dob->toDateString())->toBe(now()->subYears(30)->toDateString());
});

// TT-4.10e/SCRUM-294: the flash must actually reach the next page load as an Inertia prop, not
// just exist in the session.
test('the profile page shows the pending-approval flash as an Inertia prop on the very next load', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    $this->actingAs($minor)->patch(route('profile.update'), [
        'firstName' => $minor->firstName,
        'lastName' => $minor->lastName,
        'dob' => now()->subYears(30)->toDateString(),
    ]);

    $this->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('dobChangePendingApproval', true));
});

test('resubmitting the profile form without changing dob never triggers the gate', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    $response = $this->actingAs($minor)->patch(route('profile.update'), [
        'firstName' => 'Still Minor',
        'lastName' => $minor->lastName,
        'dob' => $minor->dob->toDateString(),
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionMissing('dobChangePendingApproval');
    expect(Request::query()->whereType(RequestTypeEnum::dobChange->value)->count())->toBe(0);
});
