<?php

namespace App\Console\Commands;

use App\Actions\TwoFactor\CancelTwoFactorResetAction;
use App\Actions\TwoFactor\RequestTwoFactorResetAction;
use App\Actions\TwoFactor\ResetTwoFactorAction;
use App\Exceptions\TwoFactorException;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Support\Terminal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-34d: reset someone's two-factor sign-in from the server -- the only way for an administrator's account (the
// admin users list refuses those) or an account without a verified email (SCRUM-424), and for anyone when no super
// administrator can sign in. Only once the team has confirmed it's really the person (outside the app; see
// documentation/operations/two-factor-reset-identity-check.md). Shows who was found and asks first.
//
// SCRUM-424: it schedules the reset like the admin list does (it applies after the wait unless cancelled); --cancel
// stops one; --now applies at once -- a break-glass for, say, the last super administrator locked out: typed by hand
// only (never with --force or without a terminal), with a reason, and logged as immediate.
class ResetUserTwoFactor extends Command
{
    protected $signature = 'user:two-factor-reset
                            {user : The account\'s username or email (or its id, with --id)}
                            {--id : Look the account up by its id}
                            {--reason= : A ticket number or short note, kept with the reset (never shown to the person; no health or personal details)}
                            {--cancel : Cancel a scheduled reset instead}
                            {--now : Apply at once instead of after the wait (typed by hand only, with --reason)}
                            {--force : Don\'t ask for confirmation (not with --now)}';

    protected $description = 'Schedule (or cancel, or with --now apply) a reset of an account\'s two-factor sign-in, once the person\'s identity is confirmed.';

    public function handle(): int
    {
        $user = $this->findUser((string) $this->argument('user'));

        if (! $user) {
            $this->error($this->option('id') ? 'No account with that id.' : 'No account with that username or email.');

            return self::FAILURE;
        }

        $who = "#{$user->id} {$user->username}".($user->isAdmin() ? ' (administrator)' : '');

        // Said even with --force, so a script's output shows who it was.
        $this->line("Found {$who}.");

        if ($this->option('cancel')) {
            return $this->cancel($user, $who);
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->warn("{$who} doesn't have two-factor sign-in on. Nothing to do.");

            return self::FAILURE;
        }

        return $this->option('now') ? $this->applyNow($user, $who) : $this->schedule($user, $who);
    }

    private function schedule(User $user, string $who): int
    {
        $hours = (int) config('auth.two_factor.reset_wait_hours');

        // Security review: without a verified email nobody can cancel it by link -- a person must look and agree.
        if (! $user->hasVerifiedEmail()) {
            $this->warn("{$who} has no verified email: they won't get the cancel link, and only signing in with a code would stop it.");

            if ($this->option('force')) {
                $this->error('Not with --force for an account without a verified email: confirm it yourself.');

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirm("Schedule a reset of two-factor sign-in for {$who}? It applies in {$hours} hours unless they cancel it; they'll be told now.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $reset = RequestTwoFactorResetAction::new()->execute($user, null, TwoFactorReset::SOURCE_SERVER, $this->option('reason'));
        } catch (TwoFactorException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        Log::notice('Two-factor sign-in reset requested on the server', [...$this->logContext($user), 'reset_id' => $reset->id, 'due_at' => $reset->due_at->toIso8601String()]);

        $this->info("Reset scheduled for {$who}: it applies on {$reset->due_at->format('j F Y, H:i T')} unless cancelled.");

        return self::SUCCESS;
    }

    private function applyNow(User $user, string $who): int
    {
        if ($this->option('force') || ! $this->input->isInteractive() || ! app(Terminal::class)->attached()) {
            $this->error('--now is for a person at a terminal: it can\'t be used with --force or without one.');

            return self::FAILURE;
        }

        if (blank($this->option('reason'))) {
            $this->error('--now needs a --reason (for example the ticket number).');

            return self::FAILURE;
        }

        if (! $this->confirm("Turn off two-factor sign-in for {$who} NOW, without the wait? They'll be told, and signed out everywhere.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            // A reset already waiting is recorded as applied by this one, in the same step (code review: not "cancelled"
            // -- the person would hear "it stays on" a moment before "it's off").
            DB::transaction(function () use ($user) {
                ResetTwoFactorAction::new()->execute($user);
                TwoFactorReset::query()->where('user_id', $user->id)->pending()
                    ->update(['applied_at' => now(), 'cancel_reason' => TwoFactorReset::APPLIED_NOW]);
            });
        } catch (TwoFactorException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        // The reason stays in the terminal and the operator's ticket, not the log (security review: free text).
        Log::notice('Two-factor sign-in reset on the server', [...$this->logContext($user), 'immediate' => true, 'has_reason' => true]);

        $this->info("Two-factor sign-in is off for {$who}. They can sign in with their password and set it up again.");

        return self::SUCCESS;
    }

    private function cancel(User $user, string $who): int
    {
        if (! CancelTwoFactorResetAction::new()->forUser($user, TwoFactorReset::CANCELLED_ON_SERVER)) {
            $this->warn("{$who} has no scheduled reset.");

            return self::FAILURE;
        }

        Log::notice('Two-factor sign-in reset cancelled on the server', $this->logContext($user));

        $this->info("The scheduled reset for {$who} is cancelled.");

        return self::SUCCESS;
    }

    // Who ran it, as far as the server knows (34d ops and security reviews): the sudo user, else the process owner.
    private function logContext(User $user): array
    {
        return [
            'user_id' => $user->id,
            'admin' => $user->isAdmin(),
            // QA: get_current_user() is the script file's owner (often unnamed in the container) -- the process's own user first.
            'by' => getenv('SUDO_USER') ?: (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '') ?: get_current_user(),
            'host' => gethostname(),
            'forced' => (bool) $this->option('force'),
        ];
    }

    // A username, then an email -- separate lookups, so one account's username can't shadow another's email. An id
    // only with --id (security review: a username may be all digits).
    private function findUser(string $value): ?User
    {
        if ($this->option('id')) {
            return ctype_digit($value) ? User::query()->find((int) $value) : null;
        }

        return User::query()->where('username', $value)->first() ?? User::query()->where('email', $value)->first();
    }
}
