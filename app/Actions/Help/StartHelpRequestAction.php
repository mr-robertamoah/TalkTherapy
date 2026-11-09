<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Exceptions\HelpException;
use App\Exceptions\HelpUnavailableException;
use App\Jobs\EscalateHelpRequestJob;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\Countries;
use App\Support\HelpBroadcast;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-33: "I need help now". Anyone signed in may ask -- a paused minor and a suspended account
// too (the user's decision: a crisis outranks both); a suspended account is held to a stricter limit.
// Someone with a live request (waiting or connected) gets that one back rather than a second -- the
// unique active_user_id makes that hold even for two taps at once. The person's state then (a minor,
// suspended, their country) is kept with the request. On-call counsellors are rung once it's saved.
class StartHelpRequestAction extends Action
{
    public const TOO_MANY = 'You have asked for help several times in the last hour. If you are in danger, call your emergency number now.';

    // The once-an-hour case (a new account, a suspended one): they may have asked only once (security review).
    public const ASKED_RECENTLY = 'You asked for help a short time ago. If you are in danger, call your emergency number now.';

    public function execute(User $user, bool $anonymous = false, ?string $note = null, ?string $ip = null): HelpRequest
    {
        if ($live = $this->liveRequestOf($user)) {
            return $live;
        }

        // SCRUM-395: the kill switch (a live request, above, still comes back).
        if (! config('help.enabled')) {
            throw new HelpUnavailableException;
        }

        $suspended = $user->isAccountSuspended();
        $key = "help-requests:{$user->id}";
        $limit = self::limitFor($user, $suspended);
        // Also per address (security review), against a few throwaway accounts ringing everyone.
        $ipKey = $ip ? 'help-requests-ip:'.sha1($ip) : null;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new HelpException($limit === 1 ? self::ASKED_RECENTLY : self::TOO_MANY, 429);
        }
        if ($ipKey && RateLimiter::tooManyAttempts($ipKey, max(1, (int) config('help.requests_per_hour_per_address')))) {
            throw new HelpException(self::TOO_MANY, 429);
        }

        try {
            $request = HelpRequest::query()->create([
                'user_id' => $user->id,
                'active_user_id' => $user->id,
                'status' => HelpRequestStatusEnum::waiting->value,
                'anonymous' => $anonymous,
                'was_minor' => ! $user->isAdult(),
                'was_suspended' => $suspended,
                'country_code' => Countries::codeFor($user->country),
                'note' => $this->cleanNote($note),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another tap got there first.
            return $this->liveRequestOf($user) ?? throw new HelpException(self::TOO_MANY, 429);
        }

        // TT-4.4b: administrators see it straight away; they're alerted if it waits.
        HelpBroadcast::queueChanged($request->id);
        EscalateHelpRequestJob::dispatch($request->id)->delay(now()->addSeconds((int) config('help.first_alert_seconds')));
        EscalateHelpRequestJob::dispatch($request->id)->delay(now()->addSeconds((int) config('help.second_alert_seconds')));

        RateLimiter::hit($key, 3600);
        if ($ipKey) {
            RateLimiter::hit($ipKey, 3600);
        }
        // Within the platform-wide cap (SCRUM-395) -- new or unverified accounts share their own, so a flood
        // of throwaway accounts can't stop established ones being rung. An administrator's "ring again"
        // isn't capped.
        RingAvailableCounsellorsAction::new()->execute($request, capBucket: self::isNewOrUnverified($user) ? 'new' : 'established');

        return $request;
    }

    // The strictest that applies: a suspended account's, a new or unverified account's (SCRUM-395), or the
    // usual -- never under 1 (review: a blank or 0 setting must never shut anyone out of crisis help).
    private static function limitFor(User $user, bool $suspended): int
    {
        $limits = [(int) config('help.requests_per_hour')];
        if ($suspended) {
            $limits[] = (int) config('help.suspended_requests_per_hour');
        }
        if (self::isNewOrUnverified($user)) {
            $limits[] = (int) config('help.new_account_requests_per_hour');
        }

        return max(1, min($limits));
    }

    // Under config help.new_account_hours old, or with an email address that isn't verified. An account
    // with no email (username only) is new only by age (the user's decision: it has nothing to verify).
    public static function isNewOrUnverified(User $user): bool
    {
        $new = $user->created_at === null || $user->created_at->gt(now()->subHours((int) config('help.new_account_hours')));

        return $new || ($user->email !== null && $user->email_verified_at === null);
    }

    // SCRUM-395 (the user's decision): a request that expired without anyone taking it gives the person
    // their allowance back, so they can ask again at once.
    public static function giveBackAllowance(HelpRequest $request): void
    {
        RateLimiter::decrement("help-requests:{$request->user_id}", 3600);
    }

    private function liveRequestOf(User $user): ?HelpRequest
    {
        return HelpRequest::query()->liveFor($user)->first();
    }

    // One line, at most 200 characters (shown escaped wherever it appears).
    private function cleanNote(?string $note): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', (string) $note));

        return $note === '' ? null : mb_substr($note, 0, 200);
    }
}
