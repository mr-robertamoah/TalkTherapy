<?php

use App\Models\Discussion;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('users.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('counsellors.{counsellorId}', function ($user, $counsellorId) {
    return $user->counsellor?->id == (int) $counsellorId;
});

Broadcast::channel('sessions.{sessionId}', function ($user, $sessionId) {
    return Session::find($sessionId)?->isParticipant($user);
});

Broadcast::channel('therapies.{therapyId}', function ($user, $therapyId) {
    if (Therapy::find($therapyId)?->isParticipant($user)) {
        return ['id' => $user->id, 'name' => $user->name];
    }

    return false;
});

Broadcast::channel('discussions.{discussionId}', function ($user, $discussionId) {
    return Discussion::find($discussionId)?->isParticipant($user);
});

Broadcast::channel('messages.{messageId}', function ($user, $messageId) {
    return Message::find($messageId)?->isParty($user);
});
