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
        // SCRUM-454: Google's `hd` claim -- set only for a Google Workspace account (the domain it belongs to).
        public readonly ?string $hostedDomain = null,
    ) {}

    // Google's `email_verified` claim counts only when it is exactly true -- not "true", not 1, not missing.
    public static function fromClaims(string $sub, ?string $email, mixed $emailVerified, mixed $hostedDomain = null): self
    {
        return new self($sub, $email, $emailVerified === true, is_string($hostedDomain) && $hostedDomain !== '' ? $hostedDomain : null);
    }

    // SCRUM-454 (security review): Google vouches for the address itself only for Gmail and Workspace accounts. Any other
    // address on a Google account was confirmed once, maybe long ago -- good enough to sign in by `sub`, not to give a
    // new account that address as already confirmed.
    public function emailIsAuthoritative(): bool
    {
        if (! $this->emailVerified || ! $this->email) {
            return false;
        }

        $domain = mb_strtolower((string) substr(strrchr($this->email, '@') ?: '', 1));

        return in_array($domain, ['gmail.com', 'googlemail.com'], true) || $this->hostedDomain !== null;
    }
}
