<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Actions\Video\EjectVideoParticipantAction;
use App\Actions\Video\EndVideoSessionAction;
use App\Enums\SuspensionScopeEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-363: a token handed out before the suspension would otherwise keep the person in a live
// call. After the suspension commits:
//  - every video room of a session the suspension ended (ApplySuspensionEffectsAction) is ended;
//  - the person is taken out of any other live room -- every room for a whole-account suspension,
//    only rooms they are in as a counsellor for a counsellor-role one (as a client they stay).
// Rejoining is refused by JoinVideoSessionAction's own checks. Each step is best effort and
// logged: the suspension has already been saved.
class RemoveSuspendedPersonFromVideoAction extends Action
{
    public function execute(Suspension $suspension, Collection $endedSessions): void
    {
        $this->attempt(fn () => $this->remove($suspension, $endedSessions), ['suspension' => $suspension->id]);
    }

    private function remove(Suspension $suspension, Collection $endedSessions): void
    {
        $endedSessions->each(fn (Session $session) => $this->attempt(
            fn () => EndVideoSessionAction::new()->execute($session),
            ['session' => $session->id],
        ));

        $person = $suspension->user;
        $counsellor = $person->counsellor;

        VideoSessionParticipant::query()
            ->where('participant_type', $person->getMorphClass())
            ->where('participant_id', $person->id)
            ->whereNull('left_at')
            ->whereHas('videoSession', fn ($q) => $q->whereNull('ended_at'))
            ->with('videoSession.session.for')
            // Their live row in each room (the latest join), not a stale reconnect row.
            ->orderByDesc('joined_at')
            ->get()
            ->unique('video_session_id')
            ->filter(function (VideoSessionParticipant $participant) use ($suspension, $counsellor) {
                if ($suspension->scope == SuspensionScopeEnum::account) {
                    return true;
                }

                $therapy = $participant->videoSession?->session?->for;

                return $counsellor && ($therapy instanceof Therapy || $therapy instanceof GroupTherapy) && $therapy->isCounsellor($counsellor);
            })
            ->each(fn (VideoSessionParticipant $participant) => $this->attempt(
                fn () => EjectVideoParticipantAction::new()->execute($participant->videoSession, $participant),
                ['video_session' => $participant->video_session_id],
            ));
    }

    private function attempt(callable $step, array $context): void
    {
        try {
            $step();
        } catch (Throwable $th) {
            Log::warning('Could not remove a suspended person from a video call.', [...$context, 'error' => $th->getMessage()]);
        }
    }
}
