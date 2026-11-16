<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\ConfirmPendingEmailController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\GoogleConnectionController;
use App\Http\Controllers\Auth\GoogleSignInController;
use App\Http\Controllers\Auth\GoogleSignUpController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SetPasswordLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\TwoFactorResetCancelController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    // SCRUM-400: against scripted floods of attempts (the per-address account limit is in the controller).
    Route::post('register', [RegisteredUserController::class, 'store'])->name('register.store')->middleware('throttle:30,1,register.store');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        // SCRUM-35b (security review): a sign-in can call Have I Been Pwned afterwards; failures are limited per account already.
        ->name('login.store')
        ->middleware('throttle:30,1,login.store');

    // SCRUM-34b: the second step of signing in (a code), after the right password. Wrong codes are also counted per
    // account (TwoFactorAttempts). Not behind the feature switch: two-factor someone turned on is always asked for.
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two_factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two_factor.challenge.store')->middleware('throttle:30,1,two_factor.challenge.store');

    // SCRUM-453 (Google sign-in, SCRUM-112): a plain 404 while GOOGLE_SIGN_IN_ENABLED is off.
    Route::middleware('google_sign_in')->group(function () {
        Route::get('auth/google/redirect', [GoogleSignInController::class, 'redirect'])->name('auth.google.redirect')->middleware('throttle:30,1,auth.google.redirect');
        Route::get('auth/google/callback', [GoogleSignInController::class, 'callback'])->name('auth.google.callback')->middleware('throttle:30,1,auth.google.callback');
        // SCRUM-454: "Finish setting up" a new account for a Google account connected to nothing (PendingGoogleSignUp).
        Route::get('auth/google/finish', [GoogleSignUpController::class, 'create'])->name('auth.google.finish')->middleware('throttle:30,1,auth.google.finish');
        Route::post('auth/google/finish', [GoogleSignUpController::class, 'store'])->name('auth.google.finish.store')->middleware('throttle:10,1,auth.google.finish.store');
    });

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');
});

// SCRUM-455: the link the email brings works signed in too -- "Set a password" (profile) sends it to someone signed in
// with Google. A reset signs this browser out (NewPasswordController), like any password change elsewhere.
Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
    ->name('password.reset');

Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->name('password.store')
    // SCRUM-35: validating a new password can ask Have I Been Pwned, before the reset token is checked.
    ->middleware('throttle:10,1,password.store');

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1,verification.verify'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1,verification.send')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // SCRUM-422 (security review): it says whether a password is right -- limited like the other password checks.
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store')->middleware('throttle:6,1,password.confirm.store');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update')
        // SCRUM-35: a new password can ask Have I Been Pwned (and this checks the current password).
        ->middleware('throttle:10,1,password.update');

    // SCRUM-34: two-factor sign-in, from the profile -- each step checks the password or a code, so a low ceiling.
    // Setting it up is hidden (a 404) while TWO_FACTOR_ENABLED is off; managing it, for those who have it, never is.
    Route::middleware('two_factor')->group(function () {
        Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two_factor.setup')->middleware('throttle:6,1,two_factor.setup');
        Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two_factor.confirm')->middleware('throttle:6,1,two_factor.confirm');
    });
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two_factor.recovery_codes')->middleware('throttle:6,1,two_factor.recovery_codes');
    Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->name('two_factor.destroy')->middleware('throttle:6,1,two_factor.destroy');
    // SCRUM-424: the person stops a reset the team scheduled (from their profile; no password -- it only makes the
    // account safer).
    Route::delete('two-factor/reset', [TwoFactorResetCancelController::class, 'destroy'])->name('two_factor.reset.cancel')->middleware('throttle:10,1,two_factor.reset.cancel');

    // SCRUM-455 (Google sign-in, SCRUM-112): connecting Google from the profile (its own callback URL; a 404 while
    // switched off or GOOGLE_CONNECT_REDIRECT_URI is unset) and disconnecting it -- each after the password.
    Route::post('profile/google/connect', [GoogleConnectionController::class, 'connect'])->name('profile.google.connect')->middleware(['google_sign_in:connect', 'throttle:10,1,profile.google.connect']);
    Route::get('profile/google/callback', [GoogleConnectionController::class, 'callback'])->name('profile.google.callback')->middleware(['google_sign_in:connect', 'throttle:10,1,profile.google.callback']);
    // Not behind the switch (review): a connected Google account can always be let go of.
    Route::delete('profile/google', [GoogleConnectionController::class, 'disconnect'])->name('profile.google.disconnect')->middleware('throttle:10,1,profile.google.disconnect');
    // SCRUM-455: "Set a password" for an account without one -- the reset link, to its own verified email.
    Route::post('profile/password/link', SetPasswordLinkController::class)->name('profile.password.link')->middleware('throttle:3,10,profile.password.link');

    // SCRUM-423: an open page asking whether it is still signed in (after a session.check nudge, or on coming back to
    // the tab). An ended session gets the usual 401 from `auth`; this answers the rest with nothing.
    Route::get('session/check', [AuthenticatedSessionController::class, 'check'])->name('session.check')->middleware('throttle:60,1,session.check');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

// SCRUM-424: the "cancel this reset" link emailed to a verified address -- signed, good until the reset applies, and no
// sign-in needed (the person may have lost their phone, or never asked). Opening it only shows the button; the button
// cancels -- so a mail scanner that opens links can't cancel anything.
Route::get('two-factor/resets/{reset}/cancel', [TwoFactorResetCancelController::class, 'show'])->name('two_factor_reset.cancel.show')
    ->where('reset', '[0-9A-Za-z]{26}')->middleware(['signed', 'throttle:30,1,two_factor_reset.cancel.show']);
Route::post('two-factor/resets/{reset}/cancel', [TwoFactorResetCancelController::class, 'store'])->name('two_factor_reset.cancel')
    ->where('reset', '[0-9A-Za-z]{26}')->middleware(['signed', 'throttle:10,1,two_factor_reset.cancel']);

// SCRUM-427: the links of an email change -- confirming the new address (24 hours) and "This wasn't me" from the
// current one (4 days). Signed, bound to the exact address, no sign-in needed; opening one only shows its button.
Route::middleware('signed')->whereNumber('id')->group(function () {
    Route::get('email/confirm/{id}/{hash}', [ConfirmPendingEmailController::class, 'showConfirm'])->name('email.change.confirm.show')->middleware('throttle:30,1,email.change.confirm.show');
    Route::post('email/confirm/{id}/{hash}', [ConfirmPendingEmailController::class, 'confirm'])->name('email.change.confirm')->middleware('throttle:6,1,email.change.confirm');
    Route::get('email/stop/{id}/{hash}', [ConfirmPendingEmailController::class, 'showStop'])->name('email.change.stop.show')->middleware('throttle:30,1,email.change.stop.show');
    Route::post('email/stop/{id}/{hash}', [ConfirmPendingEmailController::class, 'stop'])->name('email.change.stop')->middleware('throttle:6,1,email.change.stop');
});
// From the profile: the link again, or drop the request.
Route::middleware('auth')->group(function () {
    Route::post('profile/email/resend', [ConfirmPendingEmailController::class, 'resend'])->name('profile.email.resend')->middleware('throttle:3,1,profile.email.resend');
    Route::delete('profile/email/pending', [ConfirmPendingEmailController::class, 'cancel'])->name('profile.email.cancel')->middleware('throttle:10,1,profile.email.cancel');
});
