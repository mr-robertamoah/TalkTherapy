<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Models\Session;
use App\Models\TherapyTopicSession;

class SetCurrentTopicOfSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO) : Session
    {
        TherapyTopicSession::query()
            ->whereSession($createSessionDTO->session)
            ->whereCurrent()
            ->update(['current' => false]);

        $sessionTopic = TherapyTopicSession::query()
            ->whereSession($createSessionDTO->session)
            ->whereTopic($createSessionDTO->therapyTopic)
            ->first();

        if ($sessionTopic)
            $sessionTopic->update(['current' => true]);
        else
            TherapyTopicSession::create([
                'session_id' => $createSessionDTO->session->id,
                'therapy_topic_id' => $createSessionDTO->therapyTopic->id,
                'current' => true
            ]);

        return $createSessionDTO->session->refresh();
    }
}