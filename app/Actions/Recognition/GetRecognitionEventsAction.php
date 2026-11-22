<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Models\CounsellorRecognitionEvent;
use Carbon\CarbonInterface;

// TT-7.11d2/SCRUM-474: the month's recognition audit for the admin page -- every decision and consent change on that
// month's recognitions, most recent first: what happened, to which counsellor, who did it (shown to administrators
// only; null when nobody in particular did) and when. At most LIMIT (a month has at most a few per counsellor).
class GetRecognitionEventsAction extends Action
{
    public const LIMIT = 200;

    public function execute(CarbonInterface $month): array
    {
        return CounsellorRecognitionEvent::query()
            ->whereHas('recognition', fn ($query) => $query->forMonth(GetRecognitionSuggestionsAction::monthStart($month)))
            ->with(['recognition.counsellor.user', 'user'])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (CounsellorRecognitionEvent $event) => [
                'id' => $event->id,
                'event' => $event->event->value,
                'counsellorId' => $event->recognition->counsellor_id,
                'counsellorName' => $event->recognition->counsellor?->getName(),
                'actorName' => $event->user?->name,
                'from' => $event->data['from'] ?? null,
                'at' => $event->created_at?->toIso8601ZuluString(),
            ])
            ->all();
    }
}
