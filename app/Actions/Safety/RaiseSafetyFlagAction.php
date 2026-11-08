<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Actions\Admin\NotifyUninvolvedAdminsAction;
use App\Enums\SafetyFlagDiscussedEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
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

    /** @param array{urgency: string, reason: string, discussedWithClient: string, emergencyServicesContacted: bool, emergencyServicesNote: ?string} $input */
    public function execute(User $counsellorUser, SafetyFlagSubject $subject, array $input): SafetyFlag
    {
        $counsellor = $counsellorUser->counsellor;
        $limitKey = "safety-flags:{$counsellor->id}";
        if (RateLimiter::tooManyAttempts($limitKey, max(1, (int) config('safety.flags_per_hour')))) {
            throw new SafetyFlagException(self::TOO_MANY, 429);
        }

        $urgency = SafetyFlagUrgencyEnum::from($input['urgency']);
        $client = $subject->client;

        try {
            $flag = DB::transaction(function () use ($counsellor, $counsellorUser, $subject, $input, $urgency, $client) {
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
                    ...SafetyFlag::snapshotFor($client, $urgency),
                ]);

                SafetyFlagEvent::query()->create([
                    'safety_flag_id' => $flag->id,
                    'action' => SafetyFlagEvent::RAISED,
                    'actor_user_id' => $counsellorUser->id,
                    'data' => ['urgency' => $urgency->value],
                ]);

                return $flag;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SafetyFlagException(self::ALREADY_OPEN, 409);
        }

        RateLimiter::hit($limitKey, 3600);

        // Committed by now: the notification is queued (afterCommit), the ping best effort.
        NotifyUninvolvedAdminsAction::new()->execute(
            fn (User $admin) => $flag->involvesAdmin($admin),
            new SafetyFlagRaisedNotification($flag->id, $flag->public_id, $urgency->label()),
            about: "safety flag {$flag->public_id}",
        );
        $flag->broadcastChanged();

        return $flag;
    }
}
