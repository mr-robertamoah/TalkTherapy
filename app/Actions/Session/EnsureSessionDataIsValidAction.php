<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SessionException;
use App\Models\Session;
use App\Models\User;
use Carbon\Carbon;

class EnsureSessionDataIsValidAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->for->isTherapy) {
            return $this->validateTherapy($createSessionDTO);
        }

        $this->validateGroupTherapy($createSessionDTO);
    }

    public function validateTherapy(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->for->status == TherapyStatusEnum::ended->value) {
            throw new SessionException('You cannot a create session for a therapy which has ended.', 422);
        }

        if (
            $createSessionDTO->for?->sessionsHeld == $createSessionDTO->for?->max_sessions
        ) {
            throw new SessionException('You cannot create a session because the maximum session for this therapy has been reached.', 422);
        }

        if (
            $createSessionDTO->for?->payment_type == TherapyPaymentTypeEnum::free->value &&
            $createSessionDTO->paymentType == TherapyPaymentTypeEnum::paid->value
        ) {
            throw new SessionException('You cannot create a PAID session for a FREE therapy.', 422);
        }

        // On an update, startTime/endTime are only present in the DTO when the request actually
        // submitted them (SCRUM-128: Carbon::parse(null) returns "now", not null, so parsing an
        // omitted field unconditionally fabricated a false "now vs now" comparison). Fall back to
        // the session's existing, already-valid times so a partial update that doesn't touch
        // timing still gets scoped conflict checks against its real schedule.
        $startTime = $createSessionDTO->startTime !== null
            ? Carbon::parse($createSessionDTO->startTime)->setTimezone(config('app.timezone'))
            : $createSessionDTO->session?->start_time?->copy()->setTimezone(config('app.timezone'));
        $endTime = $createSessionDTO->endTime !== null
            ? Carbon::parse($createSessionDTO->endTime)->setTimezone(config('app.timezone'))
            : $createSessionDTO->session?->end_time?->copy()->setTimezone(config('app.timezone'));

        if ($startTime && $endTime) {
            if (
                $startTime->copy()->addMinutes(30)->greaterThan($endTime)
            ) {
                throw new SessionException('The end time must be at least 30 minutes from the start time.', 422);
            }

            if (
                $createSessionDTO->for->sessions()
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereDateIsBetweenStartAndEndTimes($startTime)
                    ->exists()
            ) {
                throw new SessionException('The start time of a session cannot fall within the start and end time of other sessions.', 422);
            }

            if (
                $createSessionDTO->for
                    ->sessions()
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                    ->exists()
            ) {
                throw new SessionException('The session must start at least 30 minutes before or after other sessions of this therapy.', 422);
            }

            if (
                Session::query()
                    ->whereHas('for', function ($query) use ($createSessionDTO) {
                        $query->whereParticipant($createSessionDTO->for->addedby);
                    })
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                    ->exists()
            ) {
                throw new SessionException('The user has sessions that are less than 30 minutes before or after the time for this session.', 422);
            }

            if (
                Session::query()
                    ->whereHas('for', function ($query) use ($createSessionDTO) {
                        $query->whereParticipant($createSessionDTO->for->counsellor->user);
                    })
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                    ->exists()
            ) {
                throw new SessionException('Counsellor for this therapy has sessions that are less than 30 minutes before or after the time for this session.', 422);
            }
        }

        if (
            ! $createSessionDTO->for->allow_in_person &&
            $createSessionDTO->type == SessionTypeEnum::in_person->value
        ) {
            throw new SessionException('You cannot create an in-persion session for a therapy that does not allow in-person sessions.', 422);
        }
    }

    // SCRUM-108: mirrors validateTherapy() above -- the two checks that don't translate directly
    // are the double-booking ones. A GroupTherapy's addedby can be a User OR a Counsellor
    // (unlike Therapy's always-a-User addedby), and it can have several counsellors (the
    // counsellors() pivot, plus addedby itself when addedby_type is Counsellor) rather than
    // Therapy's single $therapy->counsellor -- resolved the same way GroupTherapy::getUsers()/
    // getCounsellors() already do elsewhere in this codebase.
    public function validateGroupTherapy(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->for->status == TherapyStatusEnum::ended->value) {
            throw new SessionException('You cannot a create session for a group therapy which has ended.', 422);
        }

        if (
            $createSessionDTO->for?->sessionsHeld == $createSessionDTO->for?->max_sessions
        ) {
            throw new SessionException('You cannot create a session because the maximum session for this group therapy has been reached.', 422);
        }

        if (
            $createSessionDTO->for?->payment_type == TherapyPaymentTypeEnum::free->value &&
            $createSessionDTO->paymentType == TherapyPaymentTypeEnum::paid->value
        ) {
            throw new SessionException('You cannot create a PAID session for a FREE group therapy.', 422);
        }

        $startTime = $createSessionDTO->startTime !== null
            ? Carbon::parse($createSessionDTO->startTime)->setTimezone(config('app.timezone'))
            : $createSessionDTO->session?->start_time?->copy()->setTimezone(config('app.timezone'));
        $endTime = $createSessionDTO->endTime !== null
            ? Carbon::parse($createSessionDTO->endTime)->setTimezone(config('app.timezone'))
            : $createSessionDTO->session?->end_time?->copy()->setTimezone(config('app.timezone'));

        if ($startTime && $endTime) {
            if (
                $startTime->copy()->addMinutes(30)->greaterThan($endTime)
            ) {
                throw new SessionException('The end time must be at least 30 minutes from the start time.', 422);
            }

            if (
                $createSessionDTO->for->sessions()
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereDateIsBetweenStartAndEndTimes($startTime)
                    ->exists()
            ) {
                throw new SessionException('The start time of a session cannot fall within the start and end time of other sessions.', 422);
            }

            if (
                $createSessionDTO->for
                    ->sessions()
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                    ->exists()
            ) {
                throw new SessionException('The session must start at least 30 minutes before or after other sessions of this group therapy.', 422);
            }

            $addedbyUser = $createSessionDTO->for->addedby_type === User::class
                ? $createSessionDTO->for->addedby
                : $createSessionDTO->for->addedby?->user;

            if (
                $addedbyUser &&
                Session::query()
                    ->whereHas('for', function ($query) use ($addedbyUser) {
                        $query->whereParticipant($addedbyUser);
                    })
                    ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                        $query->whereNot('id', $createSessionDTO->session->id);
                    })
                    ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                    ->exists()
            ) {
                throw new SessionException('The user has sessions that are less than 30 minutes before or after the time for this session.', 422);
            }

            foreach ($createSessionDTO->for->getCounsellors() as $counsellor) {
                if (
                    $counsellor->user &&
                    Session::query()
                        ->whereHas('for', function ($query) use ($counsellor) {
                            $query->whereParticipant($counsellor->user);
                        })
                        ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                            $query->whereNot('id', $createSessionDTO->session->id);
                        })
                        ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                        ->exists()
                ) {
                    throw new SessionException('Counsellor for this group therapy has sessions that are less than 30 minutes before or after the time for this session.', 422);
                }
            }
        }

        if (
            ! $createSessionDTO->for->allow_in_person &&
            $createSessionDTO->type == SessionTypeEnum::in_person->value
        ) {
            throw new SessionException('You cannot create an in-persion session for a group therapy that does not allow in-person sessions.', 422);
        }
    }
}
