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

    // SCRUM-36: a journal entry's text and title (characters).
    'journal_entry_max_length' => (int) env('JOURNAL_ENTRY_MAX_LENGTH', 10000),
    'journal_title_max_length' => (int) env('JOURNAL_TITLE_MAX_LENGTH', 120),
    // How many entries one person can keep (security review: storage, and the size of their data copy).
    'journal_max_entries' => (int) env('JOURNAL_MAX_ENTRIES', 5000),
    // SCRUM-38: one PDF export -- dompdf lays it out in memory at about half a second per 10,000 characters, so it is
    // capped by entries and by total characters, a line break counting as 100 (JournalExportController::weight; about
    // 7 seconds); past either, the person picks a date range.
    'journal_export_max_entries' => (int) env('JOURNAL_EXPORT_MAX_ENTRIES', 500),
    'journal_export_max_characters' => (int) env('JOURNAL_EXPORT_MAX_CHARACTERS', 150000),
    // SCRUM-438: one export of a counsellor's notes for a therapy -- notes, and characters (line breaks weighted, as above).
    'notes_export_max_notes' => (int) env('NOTES_EXPORT_MAX_NOTES', 500),
    'notes_export_max_characters' => (int) env('NOTES_EXPORT_MAX_CHARACTERS', 150000),
    // SCRUM-445: how long after an in-person session's end its counsellor can still mark (or correct) attendance.
    'attendance_window_hours' => max(1, (int) env('ATTENDANCE_WINDOW_HOURS', 48)),
];
