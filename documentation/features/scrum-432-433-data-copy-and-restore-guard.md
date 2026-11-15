# SCRUM-432 + SCRUM-433 + SCRUM-409 — Download a copy of your data, a guard on "This wasn't me", npm hold-backs removed

## What was built

- **Download a copy of your data (SCRUM-432).**
  - "What we hold about you" (`/profile/your-data`) has a **Download a copy** card. The person types their password,
    and their two-step sign-in code (or a recovery code) when two-step sign-in is on. A JSON file then downloads at
    once. Nothing is stored on the server.
  - **What's in the file:**
    - the page's facts (`summary`);
    - their therapies (deleted ones marked), the groups they're or were in, and the sessions in those (times and status);
    - the text of every message they sent as themselves;
    - their payments (and a counsellor's payouts);
    - every time the team opened their content (when and what);
    - the retention periods.
  - **What's never in it:**
    - anyone else's messages, nor the text of a message they replied to;
    - counsellor notes, safety records, case discussions and urgent-help chats. These get the same single sentence as
      the page, from one place (`App\Support\YourDataWording`);
    - a ward's or a guardian's data;
    - the counsellor's or an administrator's name;
    - the text of messages sent **as a counsellor**. Those are about clients, so they are counted only (the user's
      decision);
    - attachment files. They are listed by name, type and size.
  - **Limits:**
    - 3 tries an hour;
    - refused while a new email address is waiting (the moment an intruder would want it);
    - accounts without a password are told to contact us.
  - **Alerts:** every download is told on the account ("A copy of your data was downloaded"). It also goes by email
    when the address is verified. The alert never carries the data or a link to it.
- **"This wasn't me" no longer hands the account to an old mailbox (SCRUM-433).**
  - When an account with no email stops a change, its remembered (last verified) address is put back only if it was
    remembered in the last 30 days (`EMAIL_STOP_RESTORE_DAYS`, 7–90).
  - Otherwise, or for an address remembered before this release (no date recorded), the account is still locked down
    (change dropped, signed out everywhere, password scrambled). The page then says to contact support, since there's no
    address to reset the password through.
  - Super admins see the remembered address and when it was remembered on the users list.
- **npm hold-backs removed (SCRUM-409).**
  - The four exact overrides from SCRUM-404 are gone; the `get-blob-duration` → `@babel/runtime` one stays.
  - `@babel/parser` and `@babel/helper-string-parser` move to 7.29.13 (4 Nov).
  - `follow-redirects` 1.16.2 and `postcss-selector-parser` 6.1.5 stay. Their newer releases came out 8 Nov, under
    7 days ago.

## Try it

1. **Download (password only).** Sign in as `your_data_demo_counsellor` / `password`.
   1. Open http://localhost:8000/profile/your-data and press **Download a copy**.
   2. Type a wrong password and press **Download**. You'll see "The password is incorrect.", and nothing downloads.
   3. Type `password`. `talktherapy-your-data-<today>.json` downloads, and a green note says it did.
   4. In the file, `messages` is empty and `summary.messages.sentAsCounsellor` is a number: no client message text.
      `payments.payouts` has the GHS 120.00 payout. `teamAccess` has the one licence open, with no administrator.
   5. The notification bell has "A copy of your data was downloaded". Mailpit (http://localhost:8027) has the email.
2. **Refused while an email change waits.** Sign in as `your_data_demo` / `password`. Download with `password`; you'll
   see that a new email address is waiting.
3. **Two-step sign-in.** Sign in as `two_factor_demo` / `password` (the code is in `documentation/seeded-data.md`;
   recovery code `demo1-aaaaa` works too, once). A code field appears next to the password; a wrong code is refused.
4. **A client's own messages.** Sign in as `your_data_demo_ward` / `password` and download. The file has only the ward's
   own data, and none of `your_data_demo`'s.
5. **The restore guard.** The behaviour needs time travel, so it is in the tests (`tests/Feature/PendingEmailTest.php`,
   the SCRUM-433 block). What admins see: as the super admin `mr_robertamoah`, open Administrator → Users and search
   `restore_guard`. `restore_guard_recent` shows "previously verified: restore.guard.recent@example.com (remembered
   …3 days ago)" and `restore_guard_old` shows "(date not recorded)". An ordinary admin sees neither.

## Test data

- `your_data_demo`, `your_data_demo_ward` and `your_data_demo_counsellor` (password `password`), already seeded by
  `YourDataSeeder`. See `documentation/seeded-data.md`.
- `your_data_demo` has a waiting email, so its download is refused. That is the refusal to look at. If it was confirmed
  or stopped since seeding, ask for one again from the profile (Edit → email → `your.data.new@example.com`).
- `restore_guard_recent` and `restore_guard_old` (password `password`, no email), from `RestoreGuardSeeder`. Run it on
  an existing database with `docker compose exec -u www-data php php artisan db:seed --class=RestoreGuardSeeder`.

## Where it lives

- `App\Support\YourDataScope`: what counts as the person's own, shared by the page (`GetYourDataAction`) and the copy.
- `App\Actions\User\ExportYourDataAction`: the JSON, written in pieces, with explicit columns for every row.
- `App\Http\Controllers\YourDataDownloadController`: `POST /profile/your-data/download`
  (`profile.your-data.download`, 3/hour).
- `App\Notifications\DataCopyDownloadedNotification`, `App\Support\YourDataWording`.
- `App\Actions\User\StopEmailChangeAction` (the restore window), the `users.last_verified_email_at` column,
  `auth.email_stop_restore_days`.
- Tests: `tests/Feature/YourDataExportTest.php`, `tests/Feature/PendingEmailTest.php`.
