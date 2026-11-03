<?php

use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Services\MessageService;
use App\Support\AdminBypass;
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
    $therapy = Therapy::find($therapyId);
    if (! $therapy) {
        return false;
    }

    // SCRUM-367: participants only. A counsellor consulted in a case discussion used to be let in
    // too, which showed the client that a discussion exists (their presence) and showed the
    // consultant the client's roster; discussions now reach counsellors on their own channels.
    if (! $therapy->isParticipant($user)) {
        return false;
    }

    // SCRUM-379: the one masking rule (Therapy::isMaskedToOthers()) -- the same name the video call
    // shows. SCRUM-357: this channel's key for the user, never their id (User::getAuthIdentifierForBroadcasting).
    return ['id' => $user->getAuthIdentifierForBroadcasting(), 'name' => $therapy->displayNameOf($user)];
});

Broadcast::channel('groupTherapies.{groupTherapyId}', function ($user, $groupTherapyId) {
    $therapy = GroupTherapy::find($groupTherapyId);
    if (! $therapy) {
        return false;
    }

    // SCRUM-367: participants only. A counsellor consulted in a case discussion used to be let in
    // too, which showed the client that a discussion exists (their presence) and showed the
    // consultant the client's roster; discussions now reach counsellors on their own channels.
    if (! $therapy->isParticipant($user)) {
        return false;
    }

    // SCRUM-379: the one masking rule (GroupTherapy::isMaskedToOthers()) -- never a counsellor, and
    // a member by the group flag OR their own membership row's flag. SCRUM-357: this channel's key
    // for the user, never their id (User::getAuthIdentifierForBroadcasting).
    return ['id' => $user->getAuthIdentifierForBroadcasting(), 'name' => $therapy->displayNameOf($user)];
});

Broadcast::channel('discussions.{discussionId}', function ($user, $discussionId) {
    $discussion = Discussion::find($discussionId);
    $counsellor = $user->counsellor;

    if (! $discussion || ! $counsellor || ! $discussion?->isParticipant($counsellor)) {
        return false;
    }

    return [
        'id' => $counsellor->id,
        'userId' => $user->id,
        'name' => $counsellor->getName(),
        'url' => $counsellor->avatar?->url,
    ];
});

// SCRUM-195: whoever may see the message at all -- the same rule as api.messages.show and
// message replies (MessageService::userCanSeeMessage), so live edits/deletes reach every viewer of
// the message. The payload is restricted for everyone (MessageBroadcastTrait), never per-viewer.
// SCRUM-365: not an administrator who could only see it as an administrator -- those reads are
// recorded (SCRUM-360) and a subscription isn't, so admins fetch instead of listening.
Broadcast::channel('messages.{messageId}', function ($user, $messageId) {
    $message = Message::withTrashed()->find($messageId);

    if ($message && $user->isAdmin() && ! $message->isParty($user) && AdminBypass::applies($user, AdminBypass::contextOf($message))) {
        return false;
    }

    return $message
        && ($message->isParty($user) || MessageService::new()->userCanSeeMessage($message, $user));
});
