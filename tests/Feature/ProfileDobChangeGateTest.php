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
