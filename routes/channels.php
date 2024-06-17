<?php

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
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

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('counsellors.{counsellorId}', function ($user, $counsellorId) {
    return $user->counsellor?->id == (int) $counsellorId;
});

Broadcast::channel('sessions.{sessionId}', function ($user, $sessionId) {
    return Session::find($sessionId)?->isParticipant($user);
});

Broadcast::channel('therapies.{therapyId}', function ($user, $therapyId) {
    $therapy = Therapy::find($therapyId);

    if (!$therapy || !$therapy?->isParticipant($user)) return false;

    $isUser = ($therapy->addedby_type == User::class && $therapy->addedby_id == $user->id) ||
        ($therapy->addedby_type == Counsellor::class && $therapy->addedby->user_id == $user->id);

    $name = $user->name;

    if ($isUser && $therapy->anonymous)
        $name = 'Client (Anonymous User)';

    return ['id' => $user->id, 'name' => $name];
});

Broadcast::channel('discussions.{discussionId}', function ($user, $discussionId) {
    $discussion = Discussion::find($discussionId);
    $counsellor = $user->counsellor;

    if (!$discussion || $counsellor || !$discussion?->isParticipant($counsellor)) return false;

    return [
        'id' => $counsellor->id,
        'userId' => $user->id, 
        'name' => $counsellor->getName(),
        'avatar' => $counsellor->avatar,
    ];
});

Broadcast::channel('messages.{messageId}', function ($user, $messageId) {
    return Message::withTrashed()->find($messageId)?->isParty($user);
});
