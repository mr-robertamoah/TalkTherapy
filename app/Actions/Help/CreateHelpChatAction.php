<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;

// SCRUM-33: what a help chat runs in -- a free, private therapy between the person and the counsellor
// (the person its client, so the usual "a therapy is created by its client" holds) with one online
// session already in progress, so the existing audio-call machinery works unchanged. Marked
// is_help_chat, so no other list or count shows it; nothing else is set off (no stars, no
// notifications, no assistance request). The person's opening note, if any, is the first message.
// Called inside AcceptHelpRequestAction's transaction.
class CreateHelpChatAction extends Action
{
    public function execute(HelpRequest $request, Counsellor $counsellor): void
    {
        $person = $request->user;

        $therapy = new Therapy;
        $therapy->forceFill([
            'name' => 'Urgent help',
            'background_story' => 'A quick help chat.',
            'session_type' => TherapySessionTypeEnum::once->value,
            'payment_type' => TherapyPaymentTypeEnum::free->value,
            'status' => TherapyStatusEnum::in_session->value,
            'public' => false,
            'anonymous' => (bool) $request->anonymous,
            'allow_in_person' => false,
            'max_sessions' => 1,
            'counsellor_id' => $counsellor->id,
            'client_was_minor_at_creation' => (bool) $request->was_minor,
            'is_help_chat' => true,
        ]);
        $therapy->addedby()->associate($person);
        $therapy->save();

        $session = new Session;
        $session->forceFill([
            'name' => 'Urgent help',
            'about' => 'A quick help chat.',
            'type' => SessionTypeEnum::online->value,
            'payment_type' => TherapyPaymentTypeEnum::free->value,
            'status' => SessionStatusEnum::in_session->value,
            'start_time' => now(),
            'end_time' => now()->addMinutes((int) config('help.session_minutes')),
            'is_help_chat' => true,
        ]);
        $session->for()->associate($therapy);
        $session->addedby()->associate($counsellor);
        $session->save();

        $request->forceFill(['therapy_id' => $therapy->id, 'session_id' => $session->id])->save();

        if ($request->note) {
            SendHelpMessageAction::new()->store($request, $person, $request->note);
        }
    }
}
