<?php

namespace App\Support;

// SCRUM-432: the one sentence about records kept to keep someone safe -- the same on "What we hold about you" and in
// the copy of their data, so neither can say more (or less) than the other. Nothing about urgent help is ever counted
// or listed; this is all either says. Awaiting legal review (SCRUM-364).
class YourDataWording
{
    public static function otherRecords(): string
    {
        $helpChatDays = RetentionPeriods::days()['helpChat'];

        return 'Your counsellor may keep private notes about your sessions and messages, and the TalkTherapy team may '
            .'hold records made to keep you safe, such as urgent help chats or a safety concern a counsellor raised. '
            ."They aren't listed or counted here. An urgent help chat's messages are deleted {$helpChatDays} days after "
            .'the chat ended; a record that help was asked for is kept.';
    }
}
