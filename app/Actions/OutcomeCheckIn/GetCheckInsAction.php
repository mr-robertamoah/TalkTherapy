<?php

namespace App\Actions\OutcomeCheckIn;

use App\Actions\Action;
use App\Enums\OutcomeMeasureEnum;
use App\Exceptions\OutcomeCheckInException;
use App\Models\OutcomeCheckIn;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-37 (TT-5.2): a therapy's check-ins, newest first (the last 50) -- for its counsellor all of them, for its
// client their own. Anyone else gets a 404.
class GetCheckInsAction extends Action
{
    public const LIMIT = 50;

    public function execute(User $viewer, ?Therapy $therapy): array
    {
        $isCounsellor = CheckInAccess::isCounsellor($viewer, $therapy);

        if (! $isCounsellor && ! CheckInAccess::isClient($viewer, $therapy)) {
            throw OutcomeCheckInException::notFound();
        }

        $checkIns = OutcomeCheckIn::query()
            ->where('therapy_id', $therapy->id)
            ->when(! $isCounsellor, fn ($q) => $q->where('client_user_id', $viewer->id))
            ->latest('requested_at')->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return [
            'checkIns' => $checkIns->map(fn (OutcomeCheckIn $checkIn) => self::present($checkIn, $isCounsellor))->all(),
            'measures' => collect(OutcomeMeasureEnum::cases())->map(fn ($m) => $m->definition()->toArray())->all(),
            'canSend' => CheckInAccess::canSend($viewer, $therapy),
            'viewerIs' => $isCounsellor ? 'counsellor' : 'client',
        ];
    }

    // What one check-in shows. The client sees their own answers, score and level (the user's decision); the
    // counsellor the same, and whether it needs attention.
    public static function present(OutcomeCheckIn $checkIn, bool $forCounsellor): array
    {
        // Answered -- unless the client's account was since erased, which clears the answers (never a made-up 0).
        $completed = $checkIn->status === OutcomeCheckIn::COMPLETED && $checkIn->total_score !== null;

        return [
            'id' => $checkIn->id,
            'measure' => $checkIn->getAttribute('measure'),
            'state' => $checkIn->status === OutcomeCheckIn::COMPLETED && ! $completed ? 'erased' : $checkIn->state(),
            'requestedAt' => $checkIn->requested_at?->toIso8601String(),
            'expiresAt' => $checkIn->expires_at?->toIso8601String(),
            'completedAt' => $checkIn->completed_at?->toIso8601String(),
            'answers' => $completed ? $checkIn->answers : null,
            'score' => $completed ? (int) $checkIn->total_score : null,
            'level' => $completed ? $checkIn->band : null,
            'needsAttention' => $forCounsellor && $checkIn->needs_attention,
            'acknowledgedAt' => $forCounsellor ? $checkIn->attention_acknowledged_at?->toIso8601String() : null,
        ];
    }
}
