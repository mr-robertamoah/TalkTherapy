<?php

namespace App\Services;

use App\Models\Session;
use App\Models\User;
use App\Notifications\SessionDueNotification;
use Illuminate\Support\Facades\Notification;

class AppService extends Service
{
    public function notifyParticipantsOfStartingSessions()
    {
        $this->alertTherapySessionParticipants();


    }

    private function alertTherapySessionParticipants()
    {
        $query = Session::query();
        
        $sessions->forEach(function ($session) {
            Notification::send($session->users, new SessionDueNotification($session));
        });

    }

    private function alertGroupTherapySessionParticipants()
    {
        $participants = [];

        
    }
}