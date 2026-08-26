<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

// The 'messages' rate limiter is registered in RouteServiceProvider and applied to the
// message create/update/delete routes via ->middleware('throttle:messages') (SCRUM-20 M5).
//
// phpunit.xml sets CACHE_STORE=array so tests get a private, in-memory cache per process
// (config/cache.php's 'default' used to read only the legacy CACHE_DRIVER env var, making that
// override a no-op and causing cross-worker rate-limiter corruption under `--parallel` --
// SCRUM-105). Cache::flush() before/after each test here still isolates this file's own
// rate-limit counters from each other sequentially within the same process.
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
