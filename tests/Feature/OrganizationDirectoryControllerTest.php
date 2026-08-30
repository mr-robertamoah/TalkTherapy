<?php

use App\Models\Organization;
use App\Models\User;

// SCRUM-161 (TT-6.6c): any authenticated user can browse the directory, not just an org's own
// admins -- this is how a counsellor/member discovers an org to apply to in the first place.

test('an authenticated user can browse the organization directory via the real route', function () {
    $organization = Organization::factory()->create(['verified_at' => now()]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organizations');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $organization->id]);
});

test('an unverified organization does not appear in the directory', function () {
    Organization::factory()->create(['verified_at' => null]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organizations');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('the directory response never leaks admin-only fields', function () {
    Organization::factory()->create([
        'legal_name' => 'Should Not Leak Ltd',
        'registration_number' => 'REG-SECRET-1',
        'email' => 'admin-only@example.com',
        'phone' => '+233000000001',
        'verified_at' => now(),
    ]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organizations');

    $response->assertOk();
    $json = $response->json();
    $organizationData = json_encode($json['data']);

    expect($organizationData)->not->toContain('Should Not Leak Ltd');
    expect($organizationData)->not->toContain('REG-SECRET-1');
    expect($organizationData)->not->toContain('admin-only@example.com');
    expect($organizationData)->not->toContain('+233000000001');
});

test('the directory can be filtered by isProvider via the real route', function () {
    $provider = Organization::factory()->create(['is_provider' => true, 'is_consumer' => false, 'verified_at' => now()]);
    $consumer = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organizations?isProvider=1');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $provider->id]);
    $response->assertJsonMissing(['id' => $consumer->id]);
});

test('a guest cannot browse the organization directory', function () {
    Organization::factory()->create(['verified_at' => now()]);

    $response = $this->getJson('/organizations');

    $response->assertUnauthorized();
});
