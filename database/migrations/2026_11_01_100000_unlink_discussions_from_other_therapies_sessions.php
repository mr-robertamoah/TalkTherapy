<?php

use App\Models\Discussion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// SCRUM-352: before discussions were checked, one could be tied to another therapy's session -- and
// DiscussionResource shows that session to the discussion's counsellors. Unlinks any such session
// (the discussion itself stays), logging each one. Not reversible.
return new class extends Migration
{
    public function up(): void
    {
        Discussion::query()->whereNotNull('session_id')->with('session')->lazyById()->each(function (Discussion $discussion) {
            $session = $discussion->session;

            if (! $session || ($session->for_type === $discussion->for_type && (int) $session->for_id === (int) $discussion->for_id)) {
                return;
            }

            Log::warning('SCRUM-352: unlinking a discussion from another therapy\'s session.', [
                'discussion_id' => $discussion->id,
                'session_id' => $session->id,
                'discussion_for' => [$discussion->for_type, $discussion->for_id],
                'session_for' => [$session->for_type, $session->for_id],
            ]);

            $discussion->forceFill(['session_id' => null])->saveQuietly();
        });
    }

    public function down(): void
    {
        //
    }
};
