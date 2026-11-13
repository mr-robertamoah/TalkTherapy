<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
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

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store')
        // SCRUM-35: validating a new password can ask Have I Been Pwned, before the reset token is checked.
        ->middleware('throttle:10,1,password.store');
});

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

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update')
        // SCRUM-35: a new password can ask Have I Been Pwned (and this checks the current password).
        ->middleware('throttle:10,1,password.update');

    // SCRUM-34: two-factor sign-in, from the profile (a 404 while switched off) -- each step checks the password or a
    // code, so a low ceiling.
    Route::middleware('two_factor')->group(function () {
        Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two_factor.setup')->middleware('throttle:6,1,two_factor.setup');
        Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two_factor.confirm')->middleware('throttle:6,1,two_factor.confirm');
        Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two_factor.recovery_codes')->middleware('throttle:6,1,two_factor.recovery_codes');
        Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->name('two_factor.destroy')->middleware('throttle:6,1,two_factor.destroy');
    });

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
