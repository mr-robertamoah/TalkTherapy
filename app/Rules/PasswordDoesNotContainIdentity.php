<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

// SCRUM-35: a password mustn't contain who you are -- your username, your email, or the part of the email before the
// "@" -- in any letter case. From the account being changed and from the form itself (signing up or resetting, there's
// no signed-in user). Pieces shorter than 4 characters are ignored, so a short username doesn't rule out every word.
class PasswordDoesNotContainIdentity implements DataAwareRule, ValidationRule
{
    private const SHORTEST = 4;

    private array $data = [];

    public function __construct(private ?User $user = null) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $password = Str::lower($value);

        foreach ($this->identities() as $identity) {
            if (str_contains($password, $identity)) {
                $fail('Your password must not contain your username or email.');

                return;
            }
        }
    }

    private function identities(): array
    {
        $values = [
            $this->user?->username,
            $this->user?->email,
            $this->data['username'] ?? null,
            $this->data['email'] ?? null,
        ];

        $identities = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = Str::lower(trim($value));
            $identities[] = $value;

            if (str_contains($value, '@')) {
                $identities[] = Str::before($value, '@');
            }
        }

        return array_values(array_unique(array_filter($identities, fn (string $identity) => mb_strlen($identity) >= self::SHORTEST)));
    }
}
