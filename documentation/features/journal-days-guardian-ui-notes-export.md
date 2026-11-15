# SCRUM-439 + SCRUM-440 + SCRUM-438: guardian display, journal day notes and search, and counsellors' notes as a PDF

## What was built

### Guardian display on the profile (SCRUM-439, the user's TODO)

- "Your guardians" and "Your wards" are plain lists inside the profile card. Each row has initials, the name,
  @username and "since <date>".
  - A minor sees only "Your guardians", since minors can't be guardians.
  - An adult sees "Your guardians" first if they have any, then "Your wards".
- **Remove:** only on ward rows. The dialog names the ward, says they'll be told, and says that a minor's therapies and
  organisations pause if this was their only guardian. A ward reads that only a guardian or the team can end it.
- **Empty states** say what to do. A minor with no guardian is told their therapies wait for one.
- **Add-guardian dialog:**
  - a labelled search, with results as a list and two buttons each ("Send request" and "Make a link for them");
  - links listed down the page, with "Load more" buttons and a status line that screen readers announce.
- **Fixes:** the wrong "sending guardianship request" loading text, data printed to the browser console, and a query
  for every person in the list.
- Whether a guardianship is paused isn't shown (the user's decision).

### Journal as day notes, paginated and searchable (SCRUM-440, the user's TODO)

- **Day notes:** one solid sticky note per day, newest first. Each note shows that day's newest entry (title and the
  start of its text) and "3 entries". Notes come 12 to a page, with numbered pages.
- **Opening a day:** clicking a note opens a dialog with that day's entries in full, each with Edit and Delete.
- **Search:** covers titles and full text, ignoring case.
  - Entries are encrypted, so the server reads **only your own** entries in memory to match.
  - Nothing is stored or logged, and the term is sent in the request body, never the URL.
  - Up to 10 searches a minute; a page past the end shows the last page.
  - Results are the matching days ("1 of 3 match"). Opening one highlights the matching entries.
- Writing, editing, Delete all and Export work as before.

### Counsellors export their own notes as a PDF (SCRUM-438)

- **Where:** a "Your notes" card on a therapy page (1:1 or group), shown to its counsellor only. "Export my notes as PDF"
  asks for the password, plus the two-step code when it's on.
- **What's in it:**
  - only the notes **they** wrote, on that therapy's sessions and messages;
  - for a note on a message, only when the message was sent, never its text;
  - no co-counsellor's notes, deleted notes, notes on discussions or urgent-help chats;
- **Who can't:** a counsellor no longer active in the group, a counsellor whose role is suspended, anyone on a deleted
  therapy. An ended therapy can still be exported.
  - an anonymous client isn't named, and there are no names in the file name.
- **Limits:** 3 exports an hour across all therapies and one PDF at a time (journal included), capped at 500 notes or 150,000 characters (line breaks weighted).
- **Alert:** the account is told once the file exists. That notice can't be switched off.
- **Shared code:** the journal export and this export use the same steps (`RunConfirmedExportAction`).
- `/privacy` tells clients that counsellors can keep a copy of their own notes.

## Try it

1. **Guardians:**
   - As `your_data_demo` (`password`), Profile shows Guardianship with "Your wards": YourData Ward, since its date, with
     Remove. Cancel the dialog.
   - As `your_data_demo_ward`, it shows "Your guardians" only, with the line about who can end it.
   - "Add a guardian" opens the new dialog.
2. **Journal:** sign in as `journal_days_demo` / `password` and open Profile → Your journal.
   - Notes for 16 days, on 2 pages. Some days have "3 entries".
   - Open a day: its entries appear in a dialog.
   - Search "anxious": one day matches, with its entry highlighted when opened.
3. **Notes PDF:** sign in as `your_data_demo_counsellor` / `password` and open "Your Data Demo Therapy".
   - In "Your notes", choose **Export my notes as PDF**, enter `password`, and the PDF downloads with the two seeded
     session notes.
   - The bell says "Your notes were exported".

## Test data

- `journal_days_demo` and the notes on "Your Data Demo Therapy" come from `JournalDaysAndNotesSeeder`. To add them:
  `docker compose exec -u www-data php php artisan db:seed --class=JournalDaysAndNotesSeeder`.
- `your_data_demo`, `your_data_demo_ward` and `your_data_demo_counsellor`: see `documentation/seeded-data.md`.

## Where it lives

- **Guardians:** `resources/js/Components/GuardianshipSection.vue`, `AddGuardianModal.vue`;
  `App\Http\Resources\GuardianshipResource`; `UserService::getGuardianship`.
- **Journal:** `JournalController::{days, search, entries}`; `resources/js/Pages/Profile/Journal.vue`.
- **Notes PDF:** `TherapyNotesExportController`, `GetOwnTherapyNotesAction`, `resources/views/pdf/therapy-notes.blade.php`,
  `TherapyNotesExportedNotification`; the shared `RunConfirmedExportAction` and `App\Support\PdfTextWeight`.
- **Tests:** `GuardianshipListTest`, `JournalTest`, `TherapyNotesExportTest`.
