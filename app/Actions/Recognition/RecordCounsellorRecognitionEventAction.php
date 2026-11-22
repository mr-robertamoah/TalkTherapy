<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Enums\CounsellorRecognitionEventEnum;
use App\Models\CounsellorRecognition;
use App\Models\CounsellorRecognitionEvent;
use App\Models\User;

// TT-7.11d1/SCRUM-473: the one writer of recognition events -- every decision and every change of consent.
class RecordCounsellorRecognitionEventAction extends Action
{
    public function execute(CounsellorRecognition $recognition, CounsellorRecognitionEventEnum $event, ?User $user, array $data = []): CounsellorRecognitionEvent
    {
        return CounsellorRecognitionEvent::create([
            'recognition_id' => $recognition->id,
            'user_id' => $user?->id,
            'event' => $event,
            'data' => $data ?: null,
        ]);
    }
}
