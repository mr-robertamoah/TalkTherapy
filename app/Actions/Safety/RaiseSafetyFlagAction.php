<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagDiscussedEnum;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Exceptions\SafetyFlagException;
use App\Jobs\ReAlertSafetyFlagJob;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\SafetyFlagNote;
use App\Models\User;
use App\Notifications\SafetyFlagRaisedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-32: a counsellor raises a safety flag (EnsureCanFlagClientAction has said they may). One open
// flag per client per therapy -- the database's unique open_client_key decides, so two at the same
// moment make one. The administrators who aren't involved are told (no name, no reason) once it's
// saved; the client and their guardians are told nothing here (TT-4.3c holds their notices for the
// handling administrator). Nothing else about the therapy changes.
class RaiseSafetyFlagAction extends Action
{
    public const ALREADY_OPEN = 'There is already an open safety flag for this person here. The administrators are dealing with it.';

    public const TOO_MANY = 'You have raised several safety flags in the last hour. Please contact the TalkTherapy team directly, and if someone is in danger call the emergency number now.';

    /** @param array{urgency: string, reason: string, discussedWithClient: string, emergencyServicesContacted: bool, emergencyServicesNote: ?string, guardianWithholdReason?: ?string} $input */
    public function execute(User $counsellorUser, SafetyFlagSubject $subject, array $input): SafetyFlag
    {
        $counsellor = $counsellorUser->counsellor;
        $limitKey = "safety-flags:{$counsellor->id}";
        if (RateLimiter::tooManyAttempts($limitKey, max(1, (int) config('safety.flags_per_hour')))) {
            throw new SafetyFlagException(self::TOO_MANY, 429);
        }

        $urgency = SafetyFlagUrgencyEnum::from($input['urgency']);
        $client = $subject->client;

        // TT-4.3c: a safeguarding reason not to tell a minor's guardians holds their notice for the handling
        // administrator, whose decision is final.
        $guardianReason = trim((string) ($input['guardianWithholdReason'] ?? ''));

        try {
            $flag = DB::transaction(function () use ($counsellor, $counsellorUser, $subject, $input, $urgency, $client, $guardianReason) {
                $snapshot = SafetyFlag::snapshotFor($client, $urgency);
                $heldForGuardians = $guardianReason !== '' && $snapshot['guardian_notice_state'] === SafetyFlagNoticeStateEnum::pending;

                $flag = SafetyFlag::query()->create([
                    'flaggable_type' => $subject->flaggable::class,
                    'flaggable_id' => $subject->flaggable->getKey(),
                    'kind' => $subject->kind,
                    'client_user_id' => $client->id,
                    'membership_id' => $subject->membershipId,
                    'counsellor_id' => $counsellor->id,
                    'urgency' => $urgency,
                    'reason' => $input['reason'],
                    'discussed_with_client' => SafetyFlagDiscussedEnum::from($input['discussedWithClient']),
                    'emergency_services_contacted' => (bool) $input['emergencyServicesContacted'],
                    'emergency_services_note' => $input['emergencyServicesNote'] ?: null,
                    'status' => SafetyFlagStatusEnum::open,
                    'open_client_key' => SafetyFlag::openKeyFor($subject->flaggable, $client),
                    'anonymous' => $subject->anonymous,
                    ...$snapshot,
                    ...($heldForGuardians ? ['guardian_notice_state' => SafetyFlagNoticeStateEnum::held] : []),
                ]);

                SafetyFlagEvent::query()->create([
                    'safety_flag_id' => $flag->id,
                    'action' => SafetyFlagEvent::RAISED,
                    'actor_user_id' => $counsellorUser->id,
                    'data' => ['urgency' => $urgency->value],
                ]);

                // The counsellor's reason as its own note, and the hold in the history -- shown as "by the
                // counsellor", never their name (reviews).
                if ($heldForGuardians) {
                    $note = SafetyFlagNote::query()->create([
                        'safety_flag_id' => $flag->id, 'author_user_id' => $counsellorUser->id,
                        'kind' => SafetyFlagNote::HOLD_GUARDIANS, 'content' => $guardianReason,
                    ]);
                    SafetyFlagEvent::query()->create([
                        'safety_flag_id' => $flag->id, 'action' => SafetyFlagEvent::GUARDIAN_NOTICE_HELD, 'actor_user_id' => $counsellorUser->id,
                        'data' => ['noteId' => $note->id, 'byCounsellor' => true],
                    ]);
                }

                return $flag;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SafetyFlagException(self::ALREADY_OPEN, 409);
        }

        RateLimiter::hit($limitKey, 3600);

        // Committed by now: the notification is queued (afterCommit), the ping best effort. Nobody to tell:
        // the fallback mailbox (TT-4.3b), recorded in the history only if it went out.
        $flag->alertUninvolved(new SafetyFlagRaisedNotification($flag->id, $flag->public_id, $urgency->label()), "safety flag {$flag->public_id}");
        $flag->broadcastChanged();

        // TT-4.3b: re-alert the super administrators if nobody has acknowledged it by then (the sweep is
        // the backstop). Pushed inside the rescue (reviews: a PendingDispatch would push after it returned).
        rescue(function () use ($flag, $urgency) {
            dispatch((new ReAlertSafetyFlagJob($flag->id))->delay(now()->addMinutes(SafetyFlag::realertMinutes($urgency))));
        }, report: true);

        return $flag;
    }
}
