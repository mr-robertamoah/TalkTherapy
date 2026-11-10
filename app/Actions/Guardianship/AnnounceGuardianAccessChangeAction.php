<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\ClientAccessResumedNotification;
use App\Notifications\GuardianAccessPausedNotification;
use App\Notifications\GuardianAccessResumedNotification;
use App\Notifications\TherapyContinuationNotification;
use App\Support\BestEffort;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

// SCRUM-382: the one "tell people" step of a minor's therapies being paused for a missing guardian
// or resumed (HandleGuardianLossAction, HandleGuardianRestoredAction). $open is what
// GetWardOpenTherapiesAction found; the callers only call this when it has something in it.
//  - the minor's open pages, and the page of each open 1:1 (never a group channel), reload;
//  - the minor is told (neutrally), and so is the counsellor of each open 1:1, with the client's
//    name as that therapy shows it -- never anything about the family.
// SCRUM-381 (the user's decision, changing SCRUM-288): each active counsellor of an open group the
// minor belongs to is told too, with the member's name as the group shows them (an anonymous member
// stays "Anonymous"), and their own open pages reload (on their own channel, never the group's).
// $continuesUntil: the resume comes from a safeguarding exception ending then (neutral wording, the
// date, never its reason). continuation() tells the same people that one was renewed or ends soon.
// Each step is best effort (BestEffort): the change has already been saved.
class AnnounceGuardianAccessChangeAction extends Action
{
    /** @param  string  $change  GuardianAccessChangedEvent::PAUSED or ::RESUMED */
    public function execute(User $ward, array $open, string $change, ?CarbonInterface $continuesUntil = null): void
    {
        $paused = $change === GuardianAccessChangedEvent::PAUSED;
        $context = ['ward' => $ward->id, 'change' => $change];
        $counsellors = $this->counsellorsOf($ward, $open);

        BestEffort::attempt(fn () => GuardianAccessChangedEvent::dispatch($ward->id, $change), 'guardian-access-broadcast', $context);
        $open['therapies']->each(fn (Therapy $therapy) => BestEffort::attempt(
            fn () => TherapyAccessChangedEvent::dispatch($therapy->id),
            'therapy-access-broadcast',
            [...$context, 'therapy' => $therapy->id],
        ));
        $counsellors->filter(fn (array $row) => $row['therapy'] instanceof GroupTherapy)
            ->pluck('user.id')->unique()
            ->each(fn (int $userId) => BestEffort::attempt(
                fn () => GuardianAccessChangedEvent::dispatch($userId, $change),
                'group-counsellor-access-broadcast',
                [...$context, 'counsellorUser' => $userId],
            ));

        BestEffort::attempt(
            fn () => $ward->notify($paused ? new GuardianAccessPausedNotification : new GuardianAccessResumedNotification($continuesUntil)),
            'guardian-access-notify',
            $context,
        );

        $counsellors->each(fn (array $row) => BestEffort::attempt(
            fn () => Notification::send($row['user'], $paused
                ? new ClientAccessPausedNotification($row['therapy'], $row['clientName'], $ward)
                : new ClientAccessResumedNotification($row['therapy'], $row['clientName'], $ward, $continuesUntil)),
            'client-access-notify',
            [...$context, 'therapy' => $row['therapy']->id, 'group' => $row['therapy'] instanceof GroupTherapy],
        ));
    }

    // SCRUM-381: a safeguarding exception was renewed (TherapyContinuationNotification::RENEWED) or
    // ends soon (::ENDING_SOON) -- to the minor and every counsellor of $open, nothing else.
    public function continuation(User $ward, array $open, string $kind, CarbonInterface $endsAt): void
    {
        $context = ['ward' => $ward->id, 'continuation' => $kind];

        BestEffort::attempt(fn () => $ward->notify(new TherapyContinuationNotification($kind, $endsAt)), 'continuation-notify', $context);

        $this->counsellorsOf($ward, $open)->each(fn (array $row) => BestEffort::attempt(
            fn () => Notification::send($row['user'], new TherapyContinuationNotification($kind, $endsAt, $row['therapy'], $row['clientName'], $ward)),
            'continuation-counsellor-notify',
            [...$context, 'therapy' => $row['therapy']->id, 'group' => $row['therapy'] instanceof GroupTherapy],
        ));
    }

    /**
     * Who counsels $ward in $open: the counsellor of each 1:1, and each active counsellor of each
     * group (never the ward themselves), with the ward's name as that therapy shows them.
     *
     * @return Collection<int, array{user: User, therapy: Therapy|GroupTherapy, clientName: string}>
     */
    public function counsellorsOf(User $ward, array $open): Collection
    {
        $oneToOne = $open['therapies']
            ->filter(fn (Therapy $therapy) => $therapy->counsellor?->user)
            ->map(fn (Therapy $therapy) => ['user' => $therapy->counsellor->user, 'therapy' => $therapy, 'clientName' => $therapy->displayNameOf($ward)]);

        $groups = $open['groups']->flatMap(function (GroupTherapy $group) use ($ward) {
            $clientName = $group->displayNameOf($ward);

            return $group->activeCounsellors()
                ->map(fn ($counsellor) => $counsellor->user)
                ->filter(fn (?User $user) => $user && ! $user->is($ward))
                ->map(fn (User $user) => ['user' => $user, 'therapy' => $group, 'clientName' => $clientName]);
        });

        return $oneToOne->concat($groups)->values();
    }
}
