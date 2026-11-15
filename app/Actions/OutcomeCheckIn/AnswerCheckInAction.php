<?php

namespace App\Actions\OutcomeCheckIn;

use App\Actions\Action;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\OutcomeCheckInException;
use App\Models\OutcomeCheckIn;
use App\Models\User;
use App\Notifications\OutcomeCheckInAttentionNotification;
use App\Notifications\OutcomeCheckInCompletedNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-37 (TT-5.2): the client answers -- every item, 0-3, once; the score and level are worked out here, never taken
// from the browser. A self-harm answer (PHQ-9 item 9 above 0) needs the counsellor's attention: they get a notice that
// can't be switched off and carries nothing of the answers (the user's decision), and the client is shown urgent help
// at once (the page, and `showCrisisSupport`). Nothing is raised automatically -- a safety flag is the counsellor's
// judgement. Returns [check-in, showCrisisSupport].
class AnswerCheckInAction extends Action
{
    public function execute(User $viewer, int $checkInId, mixed $answers): array
    {
        $checkIn = CheckInAccess::find($viewer, $checkInId);

        if ((int) $checkIn->client_user_id !== (int) $viewer->id) {
            throw OutcomeCheckInException::notFound();
        }

        $definition = $checkIn->definition();

        if (! is_array($answers) || ! array_is_list($answers) || count($answers) !== count($definition->items())
            || collect($answers)->contains(fn ($a) => ! is_int($a) || $a < 0 || $a > 3)) {
            throw new OutcomeCheckInException('Please answer every question.', 422);
        }

        $ended = $checkIn->therapy?->status === TherapyStatusEnum::ended->value;

        $answered = DB::transaction(function () use ($checkInId, $answers, $definition, $ended) {
            $locked = OutcomeCheckIn::query()->lockForUpdate()->findOrFail($checkInId);

            if ($ended || ! $locked->isOpen()) {
                return [$locked, false];
            }

            $score = $definition->score($answers);
            $locked->forceFill([
                'status' => OutcomeCheckIn::COMPLETED,
                'completed_at' => now(),
                'answers' => $answers,
                'total_score' => (string) $score,
                'band' => $definition->band($score),
                'needs_attention' => $definition->needsAttention($answers),
                'open_key' => null,
            ])->save();

            return [$locked, true];
        });
        [$checkIn, $saved] = $answered;
        $attention = $definition->needsAttention($answers);

        // The attention notice first, and each notice on its own (security review): a failure to send one never stops
        // the other, nor fails the client's answer. A self-harm answer that came too late to be saved (expired,
        // withdrawn, the therapy ended) still tells the counsellor -- nothing of what it was.
        if ($attention) {
            $this->tellCounsellor($checkIn, new OutcomeCheckInAttentionNotification($checkIn));
        }

        if (! $saved) {
            if ($attention) {
                Log::warning('Check-in answer needing attention arrived too late to be saved', ['check_in_id' => $checkIn->id]);
            }

            throw new OutcomeCheckInException(match (true) {
                $ended => 'This therapy has ended, so the check-in can no longer be answered.',
                $checkIn->isExpired() => 'This check-in has expired.',
                $checkIn->status === OutcomeCheckIn::CANCELLED => 'Your counsellor withdrew this check-in.',
                default => 'This check-in has already been answered.',
            }, 409);
        }

        // One notice, not two (QA): the attention notice already says it was answered.
        if (! $attention) {
            $this->tellCounsellor($checkIn, new OutcomeCheckInCompletedNotification($checkIn));
        }

        return [$checkIn, $checkIn->needs_attention];
    }

    // The therapy's counsellor now (security review: not necessarily who sent it). Nobody to tell is logged, never
    // silent.
    private function tellCounsellor(OutcomeCheckIn $checkIn, Notification $notification): void
    {
        $user = $checkIn->therapy?->counsellor?->user ?? $checkIn->counsellor?->user;

        if (! $user) {
            Log::warning('Check-in notice had no counsellor to go to', ['check_in_id' => $checkIn->id, 'notice' => class_basename($notification)]);

            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
