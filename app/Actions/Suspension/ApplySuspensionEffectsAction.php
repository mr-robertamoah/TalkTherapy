<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Actions\Counsellor\GetCounsellorClientsAction;
use App\Actions\Help\EndCounsellorHelpChatsAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\CounsellorUnavailableNotification;
use Illuminate\Support\Collection;

// SCRUM-359: what suspending a counsellor (either scope) does to the people relying on them, inside
// ApplySuspensionAction's transaction:
//  - scheduled sessions inside the suspension are marked FAILED ("not held"; there is no cancelled
//    status, and FAILED never bills), and one already running is ended (ABANDONED) -- an individual therapy's, and a group's only when no other
//    active counsellor can run it. Nothing is refunded automatically; a client who paid can request
//    a refund through the normal flow, and is told so;
//  - counselling requests (RequestTypeEnum::counsellingTypes) waiting on them are declined, and
//    whoever sent them is told; ones they sent are withdrawn. Others (e.g. an organisation's
//    compensation change) are about their own terms and stay pending;
//  - therapies stay open and group memberships are untouched -- the suspension is temporary;
//  - each current client is told the counsellor is unavailable (never why).
// Suspending someone who isn't a counsellor changes nothing here. Rows are locked and re-checked
// before they change, so a request accepted or a session started at the same moment isn't
// overwritten. The notifications are queued after the commit (afterCommit).
class ApplySuspensionEffectsAction extends Action
{
    public const DECLINED_REASON = 'The counsellor is not available at the moment.';

    // Returns the sessions it ended or cancelled -- the caller broadcasts them and closes their
    // video rooms once the transaction has committed.
    public function execute(Suspension $suspension): Collection
    {
        $counsellor = $suspension->user->counsellor;

        if (! $counsellor) {
            return collect();
        }

        // SCRUM-33: out of any help chat first (it ends for both; the sessions below then skip it).
        EndCounsellorHelpChatsAction::new()->execute($counsellor, 'suspension');

        [$cancelledByClient, $endedSessions] = $this->failSessions($counsellor, $suspension);
        $declinedRequesters = $this->declineRequests($counsellor);
        $this->withdrawSentRequests($counsellor);
        $name = $counsellor->getName();

        GetCounsellorClientsAction::new()->execute($counsellor, current: true)
            ->merge($declinedRequesters)
            ->unique('id')
            ->reject(fn (User $client) => $client->is($suspension->user))
            ->each(fn (User $client) => $client->notify(new CounsellorUnavailableNotification(
                $name,
                $suspension->ends_at,
                $cancelledByClient[$client->id] ?? 0,
                $declinedRequesters->contains('id', $client->id),
                $counsellor->user,
            )));

        return $endedSessions;
    }

    // [clientId => number of their sessions cancelled, the sessions]. A session already running is ended
    // (ABANDONED: "held but ended early" -- neither status bills); a scheduled one in the window FAILED.
    private function failSessions(Counsellor $counsellor, Suspension $suspension): array
    {
        $counts = [];
        $running = [SessionStatusEnum::in_session->value, SessionStatusEnum::in_session_confirmation->value];
        $alone = [];

        $ended = Session::query()
            ->where(fn ($q) => $q
                ->whereIn('status', $running)
                ->orWhere(fn ($q) => $q
                    ->where('status', SessionStatusEnum::pending->value)
                    ->where('end_time', '>', now())
                    ->when($suspension->ends_at, fn ($q) => $q->where('start_time', '<', $suspension->ends_at))))
            ->where(function ($q) use ($counsellor) {
                $q->where(fn ($q) => $q
                    ->where('for_type', Therapy::class)
                    ->whereIn('for_id', $counsellor->therapies()->select('id')))
                    ->orWhere(fn ($q) => $q
                        ->where('for_type', GroupTherapy::class)
                        ->whereIn('for_id', $counsellor->groupTherapies()->select('group_therapies.id')));
            })
            ->with(['for' => fn ($morph) => $morph->morphWith([
                GroupTherapy::class => ['users', 'addedby'],
                Therapy::class => ['addedby'],
            ])])
            ->lockForUpdate()
            ->get()
            ->filter(fn (Session $session) => $session->for instanceof Therapy
                || ($alone[$session->for_id] ??= $this->hasNoOtherCounsellor($session->for, $counsellor)))
            ->each(function (Session $session) use (&$counts, $running) {
                $session->forceFill([
                    'status' => in_array($session->status, $running, true) ? SessionStatusEnum::abandoned->value : SessionStatusEnum::failed->value,
                    'ended_at' => now(),
                ])->save();

                $this->clientsOf($session->for)->each(function (User $client) use (&$counts) {
                    $counts[$client->id] = ($counts[$client->id] ?? 0) + 1;
                });
            });

        return [$counts, $ended->values()];
    }

    // Offers they made (e.g. a proposed session time) can't be accepted any more -- withdraw them
    // rather than leave dead items in their clients' lists.
    private function withdrawSentRequests(Counsellor $counsellor): void
    {
        $counsellor->sentRequests()
            ->wherePending()
            ->whereIn('type', RequestTypeEnum::counsellingTypes())
            ->lockForUpdate()
            ->get()
            ->each(fn (Request $request) => $request->forceFill([
                'status' => RequestStatusEnum::inconsequencial->value,
                'data' => array_merge($request->data ?: [], ['reason' => self::DECLINED_REASON]),
            ])->save());
    }

    private function hasNoOtherCounsellor(GroupTherapy $group, Counsellor $counsellor): bool
    {
        return $group->activeCounsellors()
            ->reject(fn (Counsellor $other) => $other->is($counsellor) || $other->isSuspended())
            ->isEmpty();
    }

    private function clientsOf(Therapy|GroupTherapy $therapy): Collection
    {
        $owner = $therapy->addedby_type === User::class ? $therapy->addedby : null;

        return ($therapy instanceof GroupTherapy ? $therapy->users : collect())->merge([$owner])->filter();
    }

    // The people whose requests were declined.
    private function declineRequests(Counsellor $counsellor): Collection
    {
        return $counsellor->receivedRequests()
            ->wherePending()
            ->whereIn('type', RequestTypeEnum::counsellingTypes())
            ->with('from')
            ->lockForUpdate()
            ->get()
            ->map(function (Request $request) {
                $request->forceFill([
                    'status' => RequestStatusEnum::rejected->value,
                    'data' => array_merge($request->data ?: [], ['reason' => self::DECLINED_REASON]),
                ])->save();

                $from = $request->from;

                return $from instanceof Counsellor ? $from->user : ($from instanceof User ? $from : null);
            })
            ->filter()
            ->unique('id')
            ->values();
    }
}
