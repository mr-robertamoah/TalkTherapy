<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

// api.users/api.counsellors previously had no rate limiting at all -- the general 'api'
// RateLimiter is disabled (see RouteServiceProvider), so these two search endpoints were
// unthrottled despite gaining more UI call sites via SCRUM-172 (SCRUM-177).
//
// phpunit.xml sets CACHE_STORE=array so tests get a private, in-memory cache per process --
// Cache::flush() here isolates this file's own rate-limit counters, mirroring
// MessageRateLimitTest.php's own convention for the same reason.
beforeEach(fn () => Cache::flush());
afterEach(fn () => Cache::flush());

test('rapid api.users search requests beyond the per-minute limit are throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    for ($i = 0; $i < 60; $i++) {
        $response = $this->getJson(route('api.users', ['like' => 'a']));
        expect($response->getStatusCode())->not->toBe(429);
    }

    $this->getJson(route('api.users', ['like' => 'a']))
        ->assertStatus(429);
});

test('the api.users rate limit is tracked per user, not shared globally', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA);
    for ($i = 0; $i < 60; $i++) {
        $this->getJson(route('api.users', ['like' => 'a']));
    }
    $this->getJson(route('api.users', ['like' => 'a']))->assertStatus(429);

    $this->actingAs($userB);
    $response = $this->getJson(route('api.users', ['like' => 'a']));
    expect($response->getStatusCode())->not->toBe(429);
});

test('rapid api.counsellors search requests beyond the per-minute limit are throttled', function () {
    for ($i = 0; $i < 60; $i++) {
        $response = $this->getJson(route('api.counsellors', ['name' => 'a']));
        expect($response->getStatusCode())->not->toBe(429);
    }

    $this->getJson(route('api.counsellors', ['name' => 'a']))
        ->assertStatus(429);
});

// api.counsellors is public/unauthenticated, so it throttles by IP rather than user id --
// distinct from api.users above, which throttles per authenticated user.
test('the api.counsellors rate limit is tracked per ip, not shared globally', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->getJson(route('api.counsellors', ['name' => 'a']));
    }
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->getJson(route('api.counsellors', ['name' => 'a']))
        ->assertStatus(429);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->getJson(route('api.counsellors', ['name' => 'a']));
    expect($response->getStatusCode())->not->toBe(429);
});
