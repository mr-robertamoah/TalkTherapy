<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\TwoFactor\VerifyTwoFactorCodeAction;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// SCRUM-432/38: the check before anything of the person's leaves the platform (a copy of their data, their journal as
// a PDF) -- their password, and their two-factor code when it's on (the user's decision): a session left open isn't
// enough. Never while a new email is waiting (the moment an intruder would want it). One answer for a wrong password or
// a wrong code (security review), and a code is only checked -- and used up -- after the right password. Throws a
// ValidationException naming what failed; $what is the thing being taken ("a copy of your data").
class ConfirmItsYouAction extends Action
{
    // What the page asks for before it can call this (code review: one place says it).
    public function requirements(User $user): array
    {
        return [
            'passwordSet' => $user->hasPassword(),
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'emailChangeWaiting' => (bool) $user->pending_email,
        ];
    }

    public function rules(User $user): array
    {
        return [
            'current_password' => ['required', 'string'],
            'code' => [$user->hasTwoFactorEnabled() ? 'required' : 'nullable', 'string', 'max:20'],
        ];
    }

    public function execute(User $user, array $validated, string $what): void
    {
        if ($user->pending_email) {
            throw ValidationException::withMessages(['form' => "A new email address is waiting on your account. You can download {$what} once that change is finished or stopped."]);
        }

        $passwordOk = Hash::check((string) $validated['current_password'], (string) $user->getAuthPassword());

        if (! $user->hasTwoFactorEnabled()) {
            if (! $passwordOk) {
                throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
            }

            return;
        }

        try {
            $used = $passwordOk && VerifyTwoFactorCodeAction::new()->execute($user, (string) $validated['code']);
        } catch (TwoFactorException $exception) {
            throw ValidationException::withMessages(['form' => $exception->getMessage()]);
        }

        if (! $used) {
            throw ValidationException::withMessages(['form' => 'That password or code didn\'t work. Try again with the newest code from your app, or a recovery code.']);
        }
    }
}
