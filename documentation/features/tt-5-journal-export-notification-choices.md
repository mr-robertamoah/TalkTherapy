# SCRUM-36 + SCRUM-38 + SCRUM-40: a private journal, exporting it as a PDF, and choosing your notifications

## What was built

### Private journal (SCRUM-36, TT-5.1)

- **Where:** "Your journal" at `/profile/journal`, linked from a card on the profile page.
- **What you can do:**
  - Write an entry with an optional title, the text (up to 10,000 characters) and a date (today by default).
  - Edit or delete an entry, or delete the whole journal. Deleting asks first and can't be undone.
  - Show older entries, newest first.
- **Who can read it:**
  - Only the writer. A counsellor, a guardian (minors can keep a journal; their guardians see nothing), an
    organisation or an administrator gets "not found".
  - It is not something the team can open in the admin area.
  - It is stored encrypted.
- **Nobody reads it:**
  - The page says so, with a link to urgent help.
  - Nothing scans the text, and nothing is logged.
- **Deleted when the account is closed** (straight away), and again when the account is anonymised.
- **Where else it shows:**
  - "What we hold about you" shows how many entries and since when.
  - The data copy includes the entries in full.
  - `/privacy` has a "Your journal" section.
- **Limits:** 5,000 entries per person. Writes are rate-limited.

### Export as PDF (SCRUM-38, TT-5.3)

- **What you get:** "Export as PDF" on the journal page, for all entries or a date range, oldest first. The PDF is built
  on the spot and never stored or emailed.
- **Same checks as "Download a copy":**
  - your password, plus your two-step sign-in code when it's on;
  - refused while a new email address is waiting;
  - 3 tries an hour;
  - one export at a time.
- **The account is told** ("Your journal was exported") once the file exists. The address replaced by a recent email
  change is told too.
- **Size cap:** at most 500 entries or 150,000 characters per PDF (about 7 seconds to make). Past that, you're asked to
  pick a shorter range.
- **Locked down:** dompdf has no network access, no scripts and no PHP, and can only read its own template folder.
  Every value in the PDF is escaped.
- **Not included:** counsellors' session notes. That's SCRUM-437, which first encrypts them.

### Choose your notifications (SCRUM-40, TT-5.5)

- **Where:** a "What you're told about" card on the profile page, with an Email and an In app switch for each kind:
  - sessions and reminders;
  - therapies and groups;
  - your refunds;
  - for counsellors only: case discussions and organisation pay changes.
- **Defaults:** everything on.
- **Switched off entirely:** you're warned when both switches of a kind are off. "In the app" also stops the pop-up
  reminders.
- **Always sent** (and not shown as switchable):
  - safety notices and urgent help;
  - security (two-factor, email changes, data copies, journal exports);
  - suspensions;
  - guardianship and anything a guardian is told about their ward;
  - verification and age checks;
  - staff alerts;
  - notices that only go by email.
- **How it works:** one check (`App\Listeners\ApplyNotificationChoices`) applies the choices to every notification, with
  no change to the notification classes. A notification left out of `App\Support\NotificationCategories` is always
  sent, and a test makes every new notification be decided.
- **"What we hold about you"** lists the kinds you switched off.

## Try it

1. Sign in as `your_data_demo` / `password`.
2. Profile → **Your journal**. You'll see 3 seeded entries.
   1. Add one, edit it, then delete it.
   2. Go back in the browser: the deleted entry doesn't come back.
   3. "get urgent help" opens the help panel.
3. **Export as PDF.** This account has a new email waiting, so the export card says it's unavailable.
4. Sign in as `your_data_demo_ward` / `password` (a minor). Their journal has 1 entry.
   1. Export as PDF with a wrong password: you get an error.
   2. With `password`: a PDF downloads, and the bell shows "Your journal was exported".
   3. `your_data_demo` (their guardian) sees nothing of it anywhere.
5. As `your_data_demo`, go to Profile → **What you're told about**.
   1. You'll see 3 kinds. "Your refunds" has email off (seeded).
   2. Switch a kind's email and in-app both off and you get a warning. Save, then reload, and the change is still there.
   3. As `your_data_demo_counsellor` you see all 5 kinds.
6. Open `/profile/your-data`. It shows the journal count and "You switched off: your refunds (email)".

## Test data

- `your_data_demo`, `your_data_demo_ward` and `your_data_demo_counsellor` (password `password`). See
  `documentation/seeded-data.md`.
- Journal entries and the refund-email choice come from `JournalSeeder`. To add them to an existing database:
  `docker compose exec -u www-data php php artisan db:seed --class=JournalSeeder`.

## Where it lives

- **Journal:**
  - `App\Models\JournalEntry`, `App\Http\Controllers\JournalController`.
  - `resources/js/Pages/Profile/Journal.vue`.
  - Migration `2026_12_04_100000`.
- **Export:**
  - `App\Http\Controllers\JournalExportController`, `resources/views/pdf/journal.blade.php`, `config/dompdf.php`.
  - `App\Actions\User\ConfirmItsYouAction`, shared with "Download a copy".
  - `App\Notifications\JournalExportedNotification`.
- **Choices:**
  - `App\Enums\NotificationCategoryEnum`, `App\Support\NotificationCategories`, `App\Listeners\ApplyNotificationChoices`.
  - `NotificationController::channels`.
  - `resources/js/Pages/Profile/Partials/NotificationChannelsForm.vue`.
  - Migration `2026_12_04_110000`.
- **Limits:** `config/limits.php` (`journal_*`).
- **Tests:** `tests/Feature/JournalTest.php`, `tests/Feature/NotificationChoicesTest.php`, `tests/Feature/YourDataExportTest.php`.
