<?php

namespace App\DTOs;

// SCRUM-453 (Google sign-in, SCRUM-112): who Google says signed in -- only what the actions need, so no Socialite
// type reaches them. `sub` is Google's stable id for the account; the email is never used to find an account.
final class GoogleAccount
{
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email,
        public readonly bool $emailVerified,
    ) {}

    // Google's `email_verified` claim counts only when it is exactly true -- not "true", not 1, not missing.
    public static function fromClaims(string $sub, ?string $email, mixed $emailVerified): self
    {
        return new self($sub, $email, $emailVerified === true);
    }
}
