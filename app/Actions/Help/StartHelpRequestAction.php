<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Exceptions\HelpException;
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

    public function execute(User $user, bool $anonymous = false, ?string $note = null, ?string $ip = null): HelpRequest
    {
        if ($live = $this->liveRequestOf($user)) {
            return $live;
        }

        $suspended = $user->isAccountSuspended();
        $key = "help-requests:{$user->id}";
        $limit = $suspended ? (int) config('help.suspended_requests_per_hour') : (int) config('help.requests_per_hour');
        // Also per address (security review), against a few throwaway accounts ringing everyone.
        $ipKey = $ip ? 'help-requests-ip:'.sha1($ip) : null;
        if (RateLimiter::tooManyAttempts($key, $limit) || ($ipKey && RateLimiter::tooManyAttempts($ipKey, (int) config('help.requests_per_hour_per_address')))) {
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
        RingAvailableCounsellorsAction::new()->execute($request);

        return $request;
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
