<?php

// SCRUM-413: ceilings on numbers people enter, read from the environment here -- never with env() in app code,
// which returns null once config is cached (`php artisan config:cache`), so the limit silently became its default.
return [
    // A therapy's sessions, and a group therapy's counsellors and members (EnsureTherapyDataIsValidAction).
    // group_max_users is shared by hand with ConstantsEnum::groupTherapyVideoMaxParticipants -- keep them equal.
    'therapy_max_sessions' => (int) env('THERAPY_MAX_SESSIONS', 100),
    'group_therapy_max_counsellors' => (int) env('GROUP_THERAPY_MAX_COUNSELLORS', 10),
    'group_therapy_max_users' => (int) env('GROUP_THERAPY_MAX_USERS', 25),

    // A discussion's max counsellors (EnsureDiscussionDataIsValidAction): a separate cap from the group one.
    'discussion_max_counsellors_ceiling' => (int) env('DISCUSSION_MAX_COUNSELLORS_CEILING', 50),
];
