<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

// SCRUM-41: the email-verification notice and toast read one shared prop, verificationStatus -- what just happened
// with verifying, from any page.

function evnShared($test, User $user, string $from = '/')
{
    return $test->actingAs($user)->get($from)->assertOk();
}

test('opening the link says once that the email is verified, wherever they land', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->get($url)->assertRedirect(RouteServiceProvider::HOME);

    $this->actingAs($user)->get(RouteServiceProvider::HOME)->assertInertia(fn ($page) => $page->where('verificationStatus', 'email-verified'));
    $this->actingAs($user)->get(RouteServiceProvider::HOME)->assertInertia(fn ($page) => $page->where('verificationStatus', null));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('asking for the link from any page sends it and says so there', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->from('/therapies')->post(route('verification.send'))->assertRedirect('/therapies');

    Notification::assertSentTo($user, VerifyEmailNotification::class);
    evnShared($this, $user, '/therapies')->assertInertia(fn ($page) => $page->where('verificationStatus', 'verification-link-sent'));
});

test('an account with no email is sent nothing (the notice asks them to add one)', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);

    $this->actingAs($user)->from('/therapies')->post(route('verification.send'))->assertRedirect('/therapies')->assertSessionHas('status', 'no-email');

    Notification::assertNothingSent();
});

test('asking too often is answered on the page, not with an error page', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)->from('/therapies')->post(route('verification.send'));
    }

    // As the page sends it (an Inertia visit).
    $this->actingAs($user)->from('/therapies')->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post(route('verification.send'))
        ->assertRedirect('/therapies')
        ->assertSessionHas('status', 'verification-throttled');

    // A script still gets the plain 429.
    $this->actingAs($user)->postJson(route('verification.send'))->assertStatus(429);
});

test('opening the link again once verified still lands with the message', function () {
    $user = User::factory()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->get($url)->assertRedirect(RouteServiceProvider::HOME)->assertSessionHas('status', 'email-verified');
});

test('a page\'s own status never shows up as the verification status', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->withSession(['status' => 'password-updated'])->get('/therapies')
        ->assertInertia(fn ($page) => $page->where('verificationStatus', null));
});

test('the verification email says why it matters and how long the link lasts', function () {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmailNotification)->toMail($user);

    expect($mail->subject)->toBe('Verify your email for TalkTherapy')
        ->and(implode(' ', $mail->introLines))->toContain('reset your password')
        ->and(implode(' ', $mail->outroLines))->toContain('60 minutes');
});
