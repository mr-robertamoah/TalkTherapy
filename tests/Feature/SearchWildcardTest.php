<?php

use App\Models\Counsellor;
use App\Models\User;

// SCRUM-363: search terms match %, _ and ! literally, and an empty user search returns nobody.

test('a user search for % or _ matches only names that contain it', function () {
    $viewer = User::factory()->create();
    $percent = User::factory()->create(['username' => 'has%percent']);
    $underscore = User::factory()->create(['username' => 'has_underscore']);
    $plain = User::factory()->create(['username' => 'plainname']);

    $ids = fn (string $like) => collect($this->actingAs($viewer)->getJson(route('api.users', ['like' => $like]))->assertOk()->json('data'))->pluck('id');

    expect($ids('%')->all())->toBe([$percent->id])
        ->and($ids('_')->all())->toBe([$underscore->id])
        ->and($ids('plain')->all())->toBe([$plain->id]);
});

test('an empty or blank user search returns nobody', function () {
    User::factory()->count(3)->create();
    $viewer = User::factory()->create();

    foreach (['', '   '] as $like) {
        expect($this->actingAs($viewer)->getJson(route('api.users', ['like' => $like]))->assertOk()->json('data') ?? [])->toBe([]);
    }
});

test('a counsellor search for _ matches only names that contain it', function () {
    $match = Counsellor::factory()->create(['name' => 'Dr_Under', 'user_id' => User::factory()->create(['username' => 'cnsl1', 'firstName' => 'A', 'lastName' => 'B'])->id]);
    Counsellor::factory()->create(['name' => 'Dr Plain', 'user_id' => User::factory()->create(['username' => 'cnsl2', 'firstName' => 'C', 'lastName' => 'D'])->id]);

    $ids = collect($this->getJson(route('api.counsellors', ['name' => '_']))->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$match->id]);
});
