<?php

namespace App\Support;

use App\Enums\ConstantsEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;

// SCRUM-334: the one place that decides how a person is labelled to everyone ELSE in a session's
// video call. Shared by JoinVideoSessionAction (the name minted into the provider token) and
// GetVideoParticipantDisplayNameAction (the name the call UI actually shows) -- the provider-side
// name can't be trusted for display, since Daily lets any participant rename themselves from their
// own browser (setUserName()), so the UI asks our server instead, and both must agree.
final class VideoDisplayName
{
    // Security-review finding (2026-09-11): DailyVideoProvider was sending $user->name straight
    // to Daily's API as the on-screen label every OTHER participant sees -- for an anonymous
    // individual Therapy, that leaked the client's real name to the counsellor over video, exactly
    // the class of bug TT-4.9 already tracks for notifications. Resolving the display identity
    // here (not inside each provider adapter) means DailyVideoProvider/ChimeVideoProvider never
    // need to know about Therapy's own anonymity rules at all -- they just receive a name to use.
    // Mirrors TherapyTrait::addedByUserIsMaskedFor()'s own logic (anonymity only ever applies to a
    // User addedby/client, never a counsellor, and never masks someone's view of their own name --
    // moot here since the label is for OTHER participants, never the user themselves).
    //
    // TT-3.2f-g/SCRUM-324 review finding: for a GroupTherapy this used to mask only the creator, so
    // an ordinary member who is anonymous (group flag OR their own pivot flag) joined under their
    // REAL name -- shown to every other participant, and sent to the provider. Now mirrors
    // routes/channels.php's own group masking exactly: any non-counsellor anonymous participant.
    public static function for(Session $session, User $user): string
    {
        $therapy = $session->for;

        if ($therapy instanceof GroupTherapy) {
            return self::isMaskedGroupParticipant($session, $user)
                ? ConstantsEnum::anonymousUserLabel->value
                : $user->name;
        }

        $isAnonymousAddedbyUser = $therapy->addedby_type === User::class
            && $therapy->addedby->is($user)
            && $therapy->isAnonymousFor($user);

        return $isAnonymousAddedbyUser ? ConstantsEnum::anonymousUserLabel->value : $user->name;
    }

    // Any non-counsellor anonymous participant of a GroupTherapy (group flag OR their own pivot
    // flag) -- masked by name (for() above) AND, per SCRUM-332, never given a camera: the creator of
    // an anonymous group joins audio-only, matching the audio-only rule SCRUM-324 applies to
    // anonymous members' speaking grants. (Ordinary members join receive-only anyway.) Decided per
    // join: turning a group's anonymity OFF mid-call leaves the creator audio-only until they
    // rejoin -- the safe direction.
    public static function isMaskedGroupParticipant(Session $session, User $user): bool
    {
        return $session->for instanceof GroupTherapy
            && ! $session->for->isCounsellorUser($user)
            && $session->for->isAnonymousFor($user);
    }
}
