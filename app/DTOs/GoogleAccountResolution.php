<?php

namespace App\DTOs;

use App\Enums\GoogleAccountOutcomeEnum;
use App\Models\User;

// SCRUM-453: what ResolveGoogleAccountAction decided -- sign this account in, or refuse with a message written for
// people (never Google's own text). Built only through its named constructors, so each outcome carries what it needs.
final class GoogleAccountResolution
{
    private function __construct(
        public readonly GoogleAccountOutcomeEnum $outcome,
        private readonly ?User $user = null,
        private readonly ?string $refusal = null,
    ) {}

    public static function signIn(User $user): self
    {
        return new self(GoogleAccountOutcomeEnum::SignIn, user: $user);
    }

    public static function refuse(string $message): self
    {
        return new self(GoogleAccountOutcomeEnum::Refused, refusal: $message);
    }

    public function user(): User
    {
        return $this->user ?? throw new \LogicException('Only a sign-in has an account.');
    }

    public function refusal(): string
    {
        return $this->refusal ?? throw new \LogicException('Only a refusal has a message.');
    }
}
