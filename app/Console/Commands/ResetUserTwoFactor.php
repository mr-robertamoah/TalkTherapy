<?php

namespace App\Console\Commands;

use App\Actions\TwoFactor\ResetTwoFactorAction;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// SCRUM-34d: turn off someone's two-factor sign-in from the server -- the only way for an administrator's account (the
// admin users list refuses those), and for anyone when no super administrator can sign in. Only once the team has
// confirmed it's really the person (outside the app). Shows who was found and asks before doing anything (--force
// skips the question, for a script that has already checked). Logged like the admin reset.
class ResetUserTwoFactor extends Command
{
    protected $signature = 'user:two-factor-reset
                            {user : The account\'s username or email (or its id, with --id)}
                            {--id : Look the account up by its id}
                            {--force : Don\'t ask for confirmation}';

    protected $description = 'Turn off an account\'s two-factor sign-in (a lost phone and recovery codes), once the person\'s identity is confirmed.';

    public function handle(): int
    {
        $user = $this->findUser((string) $this->argument('user'));

        if (! $user) {
            $this->error($this->option('id') ? 'No account with that id.' : 'No account with that username or email.');

            return self::FAILURE;
        }

        $who = "#{$user->id} {$user->username}".($user->isAdmin() ? ' (administrator)' : '');

        if (! $user->hasTwoFactorEnabled()) {
            $this->warn("{$who} doesn't have two-factor sign-in on. Nothing to do.");

            return self::FAILURE;
        }

        // Said even with --force, so a script's output shows who it was.
        $this->line("Found {$who}.");

        if (! $this->option('force') && ! $this->confirm("Turn off two-factor sign-in for {$who}? They'll be told, and signed out everywhere.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            ResetTwoFactorAction::new()->execute($user);
        } catch (TwoFactorException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        // Who ran it, as far as the server knows (ops and security reviews): the sudo user, else the process owner.
        Log::notice('Two-factor sign-in reset on the server', [
            'user_id' => $user->id,
            'admin' => $user->isAdmin(),
            'by' => getenv('SUDO_USER') ?: get_current_user(),
            'host' => gethostname(),
            'forced' => (bool) $this->option('force'),
        ]);

        $this->info("Two-factor sign-in is off for {$who}. They can sign in with their password and set it up again.");

        return self::SUCCESS;
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
