<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

// The 'messages' rate limiter is registered in RouteServiceProvider and applied to the
// message create/update/delete routes via ->middleware('throttle:messages') (SCRUM-20 M5).
//
// phpunit.xml sets CACHE_STORE=array to isolate the cache during tests, but config/cache.php's
// 'default' store still reads the legacy CACHE_DRIVER env var (which all .env* files set to
// 'file'), so that override is a no-op and tests actually hit the real file cache store -- the
// same one rate limiter counters are written to outside of tests. Cache::flush() before/after
// each test here keeps this file's rate-limit counters isolated from other test runs without
// depending on (or trying to fix) that pre-existing store-selection mismatch, which is unrelated
// to this ticket.
beforeEach(fn () => Cache::flush());
afterEach(fn () => Cache::flush());

test('rapid message creation requests beyond the per-minute limit are throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // The request body is deliberately left invalid/empty here: the throttle middleware runs
    // before form-request validation and controller logic, so it still counts every hit
    // against the limit regardless of whether the payload would otherwise be accepted.
    for ($i = 0; $i < 30; $i++) {
        $response = $this->postJson(route('api.messages.create'), []);
        expect($response->getStatusCode())->not->toBe(429);
    }

    $this->postJson(route('api.messages.create'), [])
        ->assertStatus(429);
});

test('rapid message update requests beyond the per-minute limit are throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // A non-existent messageId means each of these fails inside the controller rather than
    // succeeding, but that's irrelevant here -- the throttle middleware still counts every hit
    // regardless of the eventual response, and none of these first 30 should be a 429.
    for ($i = 0; $i < 30; $i++) {
        $response = $this->postJson(route('api.messages.update', ['messageId' => 0]), []);
        expect($response->getStatusCode())->not->toBe(429);
    }

    $this->postJson(route('api.messages.update', ['messageId' => 0]), [])
        ->assertStatus(429);
});

test('rapid message delete requests beyond the per-minute limit are throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // A non-existent messageId means each of these fails inside the controller rather than
    // succeeding, but that's irrelevant here -- the throttle middleware still counts every hit
    // regardless of the eventual response, and none of these first 30 should be a 429.
    for ($i = 0; $i < 30; $i++) {
        $response = $this->deleteJson(route('api.messages.delete', ['messageId' => 0]));
        expect($response->getStatusCode())->not->toBe(429);
    }

    $this->deleteJson(route('api.messages.delete', ['messageId' => 0]))
        ->assertStatus(429);
});

test('rapid message delete-for-me requests beyond the per-minute limit are throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // A non-existent messageId means each of these fails inside the controller rather than
    // succeeding, but that's irrelevant here -- the throttle middleware still counts every hit
    // regardless of the eventual response, and none of these first 30 should be a 429.
    for ($i = 0; $i < 30; $i++) {
        $response = $this->deleteJson(route('api.messages.delete.me', ['messageId' => 0]));
        expect($response->getStatusCode())->not->toBe(429);
    }

    $this->deleteJson(route('api.messages.delete.me', ['messageId' => 0]))
        ->assertStatus(429);
});

test('the message rate limit is tracked per user, not shared globally', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA);
    for ($i = 0; $i < 30; $i++) {
        $this->postJson(route('api.messages.create'), []);
    }
    $this->postJson(route('api.messages.create'), [])->assertStatus(429);

    $this->actingAs($userB);
    $response = $this->postJson(route('api.messages.create'), []);
    expect($response->getStatusCode())->not->toBe(429);
});
