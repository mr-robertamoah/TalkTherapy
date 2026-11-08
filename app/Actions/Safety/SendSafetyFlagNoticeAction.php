<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\User;
use App\Notifications\GuardianSafetyNoticeNotification;
use App\Notifications\SafetyNoticeNotification;
use Closure;
use Throwable;

// SCRUM-32 (TT-4.3c): sends one of a safety flag's notices -- to the client, or to their active guardians.
// It is claimed first with one conditional update (reviews: the state, and for the sweep the flag's status
// and due time, are checked in that same UPDATE -- never on a copy loaded earlier), so the handler and the
// sweep never send it twice and the sweep never sends one the handler has just held:
// - automatically (the sweep): pending only, and $constrain adds the status and due-time conditions;
// - by the handler: pending or held.
// Sending queues the notifications. If queueing fails before anyone was notified the claim is released
// back to the state that was actually claimed (and only if it's still the one we set); after some
// recipients, it stays sent -- at least once, never silently lost, possibly twice. Nobody to send it to (a
// closed account, someone now 18 for guardians, or no active guardian): "no recipient", never "sent".
// Returns what happened: SENT, NO_RECIPIENT, NOT_CLAIMED (it wasn't waiting -- someone got there first) or
// FAILED (released for another try).
class SendSafetyFlagNoticeAction extends Action
{
    public const CLIENT = 'client';

    public const GUARDIANS = 'guardians';

    public const SENT = 'sent';

    public const NO_RECIPIENT = 'no_recipient';

    public const NOT_CLAIMED = 'not_claimed';

    public const FAILED = 'failed';

    public function execute(SafetyFlag $flag, string $which, ?User $by = null, ?Closure $constrain = null): string
    {
        $column = $which === self::CLIENT ? 'client_notice_state' : 'guardian_notice_state';
        $sentAt = $which === self::CLIENT ? 'client_notice_sent_at' : 'guardian_notice_sent_at';
        $from = $by ? [SafetyFlagNoticeStateEnum::held, SafetyFlagNoticeStateEnum::pending] : [SafetyFlagNoticeStateEnum::pending];

        $claimed = null;
        foreach ($from as $state) {
            $won = SafetyFlag::query()->whereKey($flag->id)->where($column, $state->value)
                ->when($constrain, fn ($query) => $constrain($query))
                ->update([$column => SafetyFlagNoticeStateEnum::sent->value, $sentAt => now(), 'updated_at' => now()]);
            if ($won) {
                $claimed = $state;
                break;
            }
        }
        if (! $claimed) {
            return self::NOT_CLAIMED;
        }

        $client = $flag->client()->first();
        $recipients = $this->recipients($which, $client);

        $told = 0;
        try {
            foreach ($recipients as $recipient) {
                $recipient->notify($which === self::CLIENT ? new SafetyNoticeNotification : new GuardianSafetyNoticeNotification($client));
                $told++;
            }
        } catch (Throwable $th) {
            report($th);
            if ($told === 0) {
                SafetyFlag::query()->whereKey($flag->id)->where($column, SafetyFlagNoticeStateEnum::sent->value)
                    ->update([$column => $claimed->value, $sentAt => null]);

                return self::FAILED;
            }
        }

        if ($told === 0) {
            SafetyFlag::query()->whereKey($flag->id)->where($column, SafetyFlagNoticeStateEnum::sent->value)
                ->update([$column => SafetyFlagNoticeStateEnum::noRecipient->value, $sentAt => null]);
            SafetyFlagEvent::query()->create([
                'safety_flag_id' => $flag->id, 'actor_user_id' => $by?->id, 'action' => SafetyFlagEvent::NOTICE_NO_RECIPIENT,
                'data' => ['which' => $which, 'automatic' => $by === null],
            ]);

            return self::NO_RECIPIENT;
        }

        SafetyFlagEvent::query()->create([
            'safety_flag_id' => $flag->id, 'actor_user_id' => $by?->id,
            'action' => $which === self::CLIENT ? SafetyFlagEvent::CLIENT_NOTICE_SENT : SafetyFlagEvent::GUARDIAN_NOTICE_SENT,
            'data' => ['recipients' => $told, 'automatic' => $by === null, ...($told < count($recipients) ? ['partial' => true] : [])],
        ]);

        return self::SENT;
    }

    /** @return list<User> */
    private function recipients(string $which, ?User $client): array
    {
        if (! $client || $client->trashed() || $client->anonymised_at) {
            return [];
        }

        if ($which === self::CLIENT) {
            return [$client];
        }

        // Re-checked when it goes (security review): a held notice can go weeks later -- never to the
        // guardians of someone who is now an adult.
        if ($client->isAdult()) {
            return [];
        }

        return User::query()->whereIn('id', $client->activeGuardianships()->pluck('guardian_id'))->get()->all();
    }
}
