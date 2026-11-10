<?php

namespace App\Support;

use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-360/365: whether an administrator reaches something only because they are an
// administrator -- not a participant, not a public therapy anyone may read, not their own document.
// Such reads are recorded (RecordAdminContentReadAction), and such admins are kept off a message's
// live channel (routes/channels.php), where a subscription couldn't be recorded.
class AdminBypass
{
    // A message's therapy or discussion.
    public static function contextOf(Message $message): Therapy|GroupTherapy|Discussion|null
    {
        $for = $message->for;

        return $for instanceof Session ? $for->for : ($for instanceof Discussion ? $for : null);
    }

    public static function applies(User $admin, Therapy|GroupTherapy|Discussion|null $context, ?User $owner = null): bool
    {
        if ($owner) {
            return ! $owner->is($admin);
        }

        return match (true) {
            $context instanceof Discussion => $context->isNotParticipant($admin->counsellor),
            $context instanceof Therapy, $context instanceof GroupTherapy => ! $context->public && $context->isNotParticipant($admin),
            default => true,
        };
    }
}
