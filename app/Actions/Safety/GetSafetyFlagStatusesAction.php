<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\ConstantsEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\SafetyFlag;
use App\Models\Therapy;
use App\Models\User;
use App\Support\GroupMemberKeys;

// SCRUM-32 (TT-4.3b): what a therapy's (or group's, or help chat's) counsellors see of its safety flags --
// who each is about (as they see them), when it was raised, and its status: open, being handled,
// withdrawn or resolved. Never the reason, the outcome, notes or who is handling it (the user's
// defaults: co-counsellors see that a flag exists and its status; the counsellor who raised it sees
// "resolved", not the outcome). Only the one who raised it can withdraw it. Anyone else: a 404.
class GetSafetyFlagStatusesAction extends Action
{
    public function forTherapy(?User $viewer, ?Therapy $therapy): array
    {
        if (! EnsureCanFlagClientAction::isTherapyCounsellor($viewer, $therapy)) {
            throw $this->notFound();
        }

        // An anonymised (closed) account has no name to show (TT-4.3c).
        return $this->rows($viewer, $therapy, fn (SafetyFlag $flag) => $flag->client ? $therapy->displayNameOf($flag->client) : ConstantsEnum::anonymousUserLabel->value, withClient: true);
    }

    public function forGroup(?User $viewer, ?GroupTherapy $group): array
    {
        if (! EnsureCanFlagClientAction::isGroupCounsellor($viewer, $group)) {
            throw $this->notFound();
        }

        $names = collect(GetFlaggableMembersAction::new()->labels($group, $viewer))->pluck('name', 'key');

        return $this->rows($viewer, $group, fn (SafetyFlag $flag) => $names[$flag->membership_id ? (string) $flag->membership_id : GroupMemberKeys::CREATOR] ?? 'A member');
    }

    public function forHelpChat(?User $viewer, ?HelpRequest $request): array
    {
        if (! EnsureCanFlagClientAction::answeredHelpChat($viewer, $request)) {
            throw $this->notFound();
        }

        return $this->rows($viewer, $request->therapy, fn () => $request->anonymous ? 'Anonymous' : ($request->user?->firstName ?: 'The person'));
    }

    private function rows(User $viewer, Therapy|GroupTherapy $flaggable, callable $about, bool $withClient = false): array
    {
        $mine = fn (SafetyFlag $flag) => (int) $flag->counsellor_id === (int) $viewer->counsellor?->id;

        return SafetyFlag::query()->where('flaggable_type', $flaggable::class)->where('flaggable_id', $flaggable->getKey())
            ->when($withClient, fn ($query) => $query->with('client'))->latest('id')->limit(50)->get()
            ->map(fn (SafetyFlag $flag) => [
                'id' => $flag->public_id,
                'about' => $about($flag),
                'urgency' => $flag->urgency->label(),
                'raisedAt' => $flag->created_at?->toIso8601String(),
                'status' => $flag->status->value,
                'statusLabel' => $flag->status->counsellorLabel(),
                'mine' => $mine($flag),
                'canWithdraw' => $mine($flag) && in_array($flag->status, [SafetyFlagStatusEnum::open, SafetyFlagStatusEnum::acknowledged], true),
            ])->all();
    }

    private function notFound(): SafetyFlagException
    {
        return new SafetyFlagException(EnsureCanFlagClientAction::NOT_FOUND, 404);
    }
}
