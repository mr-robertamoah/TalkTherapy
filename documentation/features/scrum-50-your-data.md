# What we hold about you (SCRUM-50, TT-8.2)

A page, `/profile/your-data` (route `profile.your-data`), listing the information TalkTherapy keeps about the
signed-in person: what is held, why, how long, and who can see it, with a link to the existing control where there is
one. It only ever shows the signed-in person's own data. There is no user in the route, and everything is built
from `$request->user()`.

- **No download button (the user's decision).** The page says "To get a copy of your data ... contact us" and links
  to the privacy policy's contact section (`/privacy#contact`, the only contact route there is).
- **Categories:** account and profile (a waiting new email address is shown masked, `n•••@e•••.org`), signing in and
  security (password set, two-step sign-in on/off, a scheduled reset's date, never any secrets), therapies (going on
  / ended, anonymous ones counted on their own with "your counsellor sees you as anonymous", deleted ones "still
  held"), sessions (in groups, only while the person was in them), messages you sent (as a counsellor too), groups and
  organisations (counts, your organisations and roles, never member lists), guardians and wards (counts), payments
  (count and total paid per currency; counsellors' payouts), documents (age/identity checks and a counsellor's
  licences with status and deletion date), notifications (count and the person's own retention), when the
  TalkTherapy team opened your content, and the counsellor role (verification status, number of distinct clients,
  no names).
- **Counsellor notes, safety flags and urgent-help records (the user's decision):** one fixed sentence saying such
  records may exist and how to ask about them, with the urgent help chat retention. No counts and no content: urgent
  help chats, their sessions and the messages in them aren't counted anywhere on the page.
- **Team access (TT-4.16):** how many times administrators opened the person's own content, with the latest 10
  (date and what was opened). It never shows which administrator, the IP address or the device. The list is an
  allow-list: their own individual therapies' pages, sessions and topics, messages they sent in sessions (replies
  and attachments too), and their own licence and identity documents. It never includes urgent help (identity
  reveals, or reads of a help chat), safety flags (identity or reason), case discussions or their messages, or a
  group's page. The page says so, and also that the log doesn't record why an ordinary therapy was opened.
- **Retention periods** are the privacy policy's own: `App\Support\RetentionPeriods::days()`, now used by both
  pages. Document deletion dates come from the deletion jobs' own rules
  (`DeleteExpiredIdentityDocumentsAction::deletesAt()`, `LicenseService::documentRetentionFor()`, which
  `applyDocumentRetention()` now uses too), so the page can't drift from what the jobs do.
- The page is marked "awaiting legal review" (SCRUM-364). Its words live in the Vue page so legal can change them
  without touching the backend.
- Linked from the profile page (a "What we hold about you" card above Delete Account) and from the privacy policy's
  "Your rights" section.

## Try it out

### Test data

From `documentation/seeded-data.md` → "What we hold about you (SCRUM-50, TT-8.2)":

| Account | Password | Use |
|---|---|---|
| `your_data_demo` | `password` | A client with something in every category, including a new email waiting for confirmation (shown masked) |
| `your_data_demo_ward` | `password` | Their ward (15). Sees 1 guardian and none of the guardian's data |
| `your_data_demo_counsellor` | `password` | A counsellor with licences, a payout and an administrator open of a licence |

On an existing database, add them with
`docker compose exec -u www-data php php artisan db:seed --class=YourDataSeeder`.

### Steps

1. Sign in as `your_data_demo` → **Profile** → the **What we hold about you** card → the link (or go to
   `/profile/your-data`).
2. You should see:
   - Account: the name, username, email (confirmed), date of birth, Female, Ghana, no profile photo.
   - Therapies: "1 therapy going on, and 0 ended", "1 anonymous therapy (0 going on). Your counsellor sees you as
     anonymous in these", and "1 deleted therapy, still held". Nothing about the urgent help chat. The counsellor
     isn't named anywhere on the page.
   - Sessions: 2. Messages: 3 (the help chat's message isn't counted). Groups: in 1 now (1 anonymously).
   - Guardians and wards: 0 guardians, 1 person you look after.
   - Payments: "1 payment in GHS, GHS 150.00 of it paid."
   - Documents: an age check accepted 5 days ago, whose document will be deleted when the identity-document period,
     counted from that decision, runs out (with the default 30 days: 25 days from now).
   - Notifications: 3, deleted after 90 days (or your own choice from the profile).
   - Team opened your content: **3 times**: your therapy page, the messages in one of your sessions, and a message
     you sent, each with a date and never a name. The seeded opens of your group's page, your help chat and an
     urgent-help reveal are not listed.
   - The "Other records" sentence at the end (with the urgent help chat retention in days), and "contact us" links
     that go to `/privacy#contact`.
3. Sign in as `your_data_demo_counsellor`: "As a counsellor" shows "verified until ..." and "1 person you have
   counselled". Documents shows a licence (checked, kept as the record of your verification) and a national ID whose
   document will be deleted on a date. Payments shows the payout. Team access shows 1 open of your licence.
4. Sign in as `your_data_demo_ward`: 1 guardian, and nothing of `your_data_demo`'s therapies, payments or documents.
5. Change a period in `.env.docker` (for example `HELP_TRANSCRIPT_RETENTION_DAYS`) and recreate the php container:
   `/privacy` and this page show the same new number.
6. Signed out, `/profile/your-data` sends you to sign in.

## Where it lives

- `app/Http/Controllers/YourDataController.php`, `app/Actions/User/GetYourDataAction.php` (facts only, a fixed
  number of queries: 9 for a client, at most 14 for a counsellor), `app/Support/RetentionPeriods.php`
  (`PrivacyController` uses it too), `routes/web.php`.
- `app/Actions/Request/DeleteExpiredIdentityDocumentsAction.php::deletesAt()`,
  `app/Services/LicenseService.php::documentRetentionFor()` / `documentRetentionForCounsellor()`.
- `resources/js/Pages/Profile/YourData.vue`, `resources/js/Pages/Profile/Partials/YourDataSection.vue`, and the links
  in `resources/js/Pages/Profile/Show.vue` and `resources/js/Pages/Privacy.vue`.
- `database/seeders/YourDataSeeder.php`. Tests: `tests/Feature/YourDataTest.php`.
