<?php

namespace App\Actions\OutcomeCheckIn;

use App\Actions\Action;
use App\Actions\Therapy\EnsureTherapyCanProceedAction;
use App\Enums\OutcomeMeasureEnum;
use App\Exceptions\OutcomeCheckInException;
use App\Models\OutcomeCheckIn;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\OutcomeCheckInRequestedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-37 (TT-5.2): a therapy's counsellor sends its client a questionnaire. One open per questionnaire per therapy
// (an expired one makes way), a daily limit per counsellor, and the client is told -- never which questionnaire.
class RequestCheckInAction extends Action
{
    public function execute(User $viewer, ?Therapy $therapy, string $measure): OutcomeCheckIn
    {
        if (! CheckInAccess::canSend($viewer, $therapy)) {
            throw OutcomeCheckInException::notFound();
        }

        $definition = (OutcomeMeasureEnum::tryFrom($measure) ?? throw new OutcomeCheckInException('Choose a questionnaire.', 422))->definition();
        EnsureTherapyCanProceedAction::new()->execute($viewer, $therapy);

        // Counted as it is checked (security review: two at once can't both slip under it).
        $limiter = 'check-ins:'.$viewer->counsellor->id;
        if (RateLimiter::attempts($limiter) >= (int) config('check_ins.per_counsellor_per_day')) {
            throw new OutcomeCheckInException('You have sent as many check-ins as you can for now. Please try again tomorrow.', 429);
        }
        RateLimiter::hit($limiter, 86400);

        $key = OutcomeCheckIn::openKeyFor($therapy->id, $definition->key());

        try {
            $checkIn = DB::transaction(function () use ($viewer, $therapy, $definition, $key) {
                // An expired one makes way for a new one.
                OutcomeCheckIn::query()->where('open_key', $key)->where('expires_at', '<=', now())->update(['open_key' => null]);

                $checkIn = new OutcomeCheckIn;
                $checkIn->forceFill([
                    'therapy_id' => $therapy->id,
                    'counsellor_id' => $viewer->counsellor->id,
                    'client_user_id' => $therapy->addedby_id,
                    'measure' => $definition->key(),
                    'measure_version' => $definition->version(),
                    'status' => OutcomeCheckIn::REQUESTED,
                    'requested_at' => now(),
                    'expires_at' => now()->addDays((int) config('check_ins.expires_days')),
                    'open_key' => $key,
                ])->save();

                return $checkIn;
            });
        } catch (UniqueConstraintViolationException) {
            throw new OutcomeCheckInException('That questionnaire is already waiting for an answer.', 409);
        }

        $checkIn->client?->notify(new OutcomeCheckInRequestedNotification($checkIn));

        return $checkIn;
    }
}
