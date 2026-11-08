<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\SafetyFlagKindEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Therapy;
use App\Models\User;
use App\Support\GroupMemberKeys;
use Carbon\CarbonInterface;

// SCRUM-32: may this counsellor flag this client? The counsellor of a 1:1 therapy (its client), any
// counsellor of a group (a member, current or former, by their key -- never a user id, SCRUM-345), or
// the counsellor who answered an urgent help chat (the person who asked) -- while it's going, or up to
// config safety.flag_window_days_after_end after it ended. Anyone else, and anything missing, gets the
// same 404.
// Deliberately never EnsureTherapyCanProceedAction (the user's decision): a flag must work on a
// paused, unpaid or lapsed-verification therapy, and for a counsellor whose counsellor role is
// suspended. A counsellor whose whole account is suspended can't reach these routes at all (the user's
// decision: they contact support).
class EnsureCanFlagClientAction extends Action
{
    public const NOT_FOUND = 'You can\'t raise a safety flag here.';

    // Who counsels where -- shared with GetSafetyFlagStatusesAction (reviews), which skips the time window.
    public static function isTherapyCounsellor(?User $viewer, ?Therapy $therapy): bool
    {
        return $therapy && ! $therapy->isHelpChat() && $viewer?->counsellor && (int) $therapy->counsellor_id === (int) $viewer->counsellor->id;
    }

    public static function isGroupCounsellor(?User $viewer, ?GroupTherapy $group): bool
    {
        return $viewer && $group && $group->isCounsellorUser($viewer);
    }

    public static function answeredHelpChat(?User $viewer, ?HelpRequest $request): bool
    {
        return $viewer && $request && $request->isAnsweredBy($viewer) && $request->therapy;
    }

    public function forTherapy(?User $viewer, ?Therapy $therapy): SafetyFlagSubject
    {
        if (! self::isTherapyCounsellor($viewer, $therapy) || ! $this->withinWindow($therapy->status, $therapy->updated_at)) {
            throw $this->notFound();
        }

        $client = $therapy->addedby;
        if (! $client instanceof User || $client->is($viewer)) {
            throw $this->notFound();
        }

        return new SafetyFlagSubject($therapy, $client, SafetyFlagKindEnum::therapy, (bool) $therapy->anonymous);
    }

    // A counsellor of the group, while they may still flag in it -- for the member list and the flag
    // alike (one rule, reviewer).
    public function forGroupCounsellor(?User $viewer, ?GroupTherapy $group): GroupTherapy
    {
        if (! self::isGroupCounsellor($viewer, $group) || ! $this->withinWindow($group->status, $group->updated_at)) {
            throw $this->notFound();
        }

        return $group;
    }

    public function forGroup(?User $viewer, ?GroupTherapy $group, ?string $memberKey): SafetyFlagSubject
    {
        $group = $this->forGroupCounsellor($viewer, $group);

        $member = GroupMemberKeys::all($group)->firstWhere('key', $memberKey);
        $client = $member['user'] ?? null;
        if (! $client || $client->is($viewer) || $group->isCounsellorUser($client)) {
            throw $this->notFound();
        }

        return new SafetyFlagSubject(
            $group, $client, SafetyFlagKindEnum::group, $group->isMaskedToOthers($client),
            $memberKey === GroupMemberKeys::CREATOR ? null : (int) $memberKey,
        );
    }

    public function forHelpChat(?User $viewer, ?HelpRequest $request): SafetyFlagSubject
    {
        if (! self::answeredHelpChat($viewer, $request) || ! $request->user) {
            throw $this->notFound();
        }

        $live = $request->status === HelpRequestStatusEnum::connected;
        if (! $live && ! ($request->status === HelpRequestStatusEnum::ended && $this->recent($request->ended_at))) {
            throw $this->notFound();
        }

        return new SafetyFlagSubject($request->therapy, $request->user, SafetyFlagKindEnum::helpChat, (bool) $request->anonymous);
    }

    // For pages: may they raise one here at all? (The button is offered only then -- reviewer: never let
    // someone write out a crisis and be refused at the end.)
    public function canFlag(callable $check): bool
    {
        if (! config('safety.enabled')) {
            return false;
        }

        try {
            $check($this);

            return true;
        } catch (SafetyFlagException) {
            return false;
        }
    }

    // Not ended, or ended recently. A therapy has no end time of its own: when it ended, it was last
    // changed (EndTherapyAction), so updated_at stands in.
    private function withinWindow($status, ?CarbonInterface $changedAt): bool
    {
        $ended = ($status instanceof TherapyStatusEnum ? $status : TherapyStatusEnum::tryFrom((string) $status)) === TherapyStatusEnum::ended;

        return ! $ended || $this->recent($changedAt);
    }

    private function recent(?CarbonInterface $endedAt): bool
    {
        return $endedAt !== null && $endedAt->gte(now()->subDays(max(1, (int) config('safety.flag_window_days_after_end'))));
    }

    private function notFound(): SafetyFlagException
    {
        return new SafetyFlagException(self::NOT_FOUND, 404);
    }
}
