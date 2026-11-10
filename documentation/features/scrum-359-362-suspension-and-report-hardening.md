# Suspension as a report outcome (SCRUM-359) and report/retention hardening (SCRUM-362)

## What was built

### SCRUM-359: suspending someone after a report

A report resolved with the outcome **"suspension recommended"** can now actually suspend the person
it is about.

- **Who can suspend:** only a **super administrator** who is not part of the report (not the
  reporter, the reported person, a participant of the therapy, or a guardian of one of them). Never
  themselves, and never another super administrator.
- **From where:** the report's page (`/administrator/reports/{id}`), in the new **Suspension**
  section. A suspension is always tied to the report it came from.
- **What it covers**, chosen by the admin:
  - **Whole account:** the person can still sign in, but every page sends them to `/suspended`
    and every API call is refused (`403`, `code: account_suspended`), live-chat channel
    authorisation included. Admin pages are refused too. Open pages leave for `/suspended` the
    moment it is applied (a broadcast on their private channel).
  - **Counsellor role only:** the person uses TalkTherapy as a client as usual, but is hidden from
    counsellor search and recommendations, cannot accept or send counselling requests, cannot
    write as a counsellor, cannot schedule, start or join sessions as one, and cannot start a
    discussion, publish a post as a counsellor, or use a counsellor invite link. Messages are judged
    by the role they hold in the conversation, so sending as their personal account doesn't get
    round it. Their clients cannot ask them for anything new.
    Reading what they already have, and filing reports, still work.
- **How long:** 7, 30 or 90 days, or until lifted. A suspension stops applying the moment its end
  passes — it is worked out from its dates on every request, so nothing waits for a scheduled job.
  A job every five minutes (`end-expired-suspensions`) records the end and emails the person.
- **What happens to a suspended counsellor's clients:**
  - upcoming sessions inside the suspension are marked **failed**, and a session already running is
    ended (**abandoned**) — an individual therapy's, and a group's only when no other active
    counsellor can run it;
  - pending requests asking them to work with someone are declined, and the sender is told; offers
    they sent themselves (e.g. a proposed session time) are withdrawn;
  - therapies stay open and group memberships are untouched;
  - each current client is emailed that the counsellor is **unavailable** until a date — never
    that they were suspended, or why — that they can request a refund for a paid session, and
    where to find another counsellor or urgent help.
- **Emails:** the suspended person is told what it covers, until when, the reason written for them
  and how to appeal (never who reported them or which admin). Guardians of a suspended minor are
  told. The person is told when it ends or is lifted.
- **Lifting early:** a super admin, with a reason (kept on the suspension and in the report history).
  Failed sessions and declined requests are not restored. Lifting while an appeal waits answers it.
- **Appeals:** one written appeal per suspension, from `/suspended`, while it is in force. Super
  admins who could decide it are emailed. A super admin **other than the one who applied it** (and
  not part of the report) upholds it or lifts it, with a note the person is emailed.
- **Admin views:** the report page lists its suspensions; `/administrator/suspensions` lists every
  suspension, appeals waiting first, with lift and appeal actions. An admin involved in one (part
  of the report behind it, the suspended person, or their guardian) sees only that it exists and
  can't act on it. Suspended admins are no longer picked to receive admin alerts.
- **History:** applying, lifting, ending, appealing and deciding each add a row to the report's
  history.

### SCRUM-362: hardening from the SCRUM-340/338 reviews

1. **`api.reports`** (the JSON list) now shows an admin a report they are part of only as
   `{id, status, involved: true, createdAt}`, like the review queue.
2. **"Part of the report"** now includes guardians of the reporter, of the reported person and of
   the reported therapy's client. Former group members were already covered: nothing ever removes
   a group membership row (pinned by a test).
3. **The confidential-read log is append-only in the database:** triggers refuse any UPDATE or
   DELETE on `confidential_message_accesses` (MySQL and SQLite).
4. **Re-pointing a report after a confidential read** needs a super admin and a written reason
   (stored in the history). It is refused for everyone while a suspension from the report is in force.
5. **Abandoned licence uploads** (a licence no verification request ever cited, not validated) lose
   their files 30 days after upload (`COUNSELLOR_LICENSE_ABANDONED_RETENTION_DAYS`). The licence
   row is kept.
6. **One shared file-deletion action** (`DeleteAttachedFilesAction`) for licence retention,
   identity-document retention and report deletion. It deletes the stored file first and keeps a
   file attached (retried next run) if that fails, and logs each deletion with ids only.
7. **`requests.responded_at`** is set once, when a request first leaves pending. Both retention
   sweeps count from it, so a later change to a decided request no longer restarts the clock.
   Existing decided requests were backfilled from `updated_at`.

## How to try it

Reseed first (destructive to local data):

```bash
docker compose exec php php artisan migrate:fresh --seed
```

### Test data

See "Suspension demo (SCRUM-359)" in `documentation/seeded-data.md`. Password `password` for all
of these; the super admin is `mr_robertamoah` (password in `.env.docker` as `SUPER_PASSWORD`).

| Account | Use it for |
|---|---|
| `mr_robertamoah` | Applying, lifting, deciding the appeal |
| `suspension_ready_counsellor` | The person to suspend (report "[Suspension demo: ready to suspend]") |
| `suspension_demo_client` | Their client: sees the "unavailable" email and the failed session |
| `suspended_counsellor_demo` | Already suspended (counsellor role): signs in as a client, hidden from search |
| `suspended_client_demo` | Already suspended (whole account), appeal waiting |
| `suspension_demo_admin` | The second super admin who "applied" the seeded suspensions |
| `suspension_waiting_client` | Has a pending request to the counsellor you suspend; it's declined |
| `suspension_demo_normal_admin` | A normal admin: no suspend form, can't lift or decide |

### 1. Suspend a counsellor

1. Sign in as `mr_robertamoah`. Go to `/administrator/reports`, choose the **resolved** filter and
   open "[Suspension demo: ready to suspend]".
2. In **Suspension**, choose "Counsellor role only", "7 days", write a reason and press **suspend**.
3. Expected: the suspension appears with status *active*; the history shows "applied a suspension".
4. Open Mailpit (http://localhost:8027):
   - `suspension.ready.counsellor@example.com` (the counsellor) got "Your counsellor role on TalkTherapy has been
     suspended", with the reason and an appeal link, and no names.
   - `suspension.demo.client@example.com` got "Your counsellor is unavailable for now", saying 1 upcoming
     session was cancelled and how to request a refund, with no mention of a suspension.
   - `suspension.waiting.client@example.com` got the same "unavailable" email, saying their
     pending request was declined.
5. Sign in as `suspension_ready_counsellor`: counsellor work is refused ("Your counsellor role is
   suspended…"); `/suspended` shows the details and the appeal form; the rest of the site works.

### 2. A suspended account

1. Sign in as `suspended_client_demo`. Every page sends you to `/suspended`, which shows the
   reason, "When an administrator lifts it", and that the appeal was sent. **Sign out** works.

### 3. Decide the appeal

1. As `mr_robertamoah`, open `/administrator/suspensions`. The waiting appeal is first.
2. **decide appeal** → write a note → **accept and lift**.
3. Expected: status *lifted*; `suspended.client.demo@example.com` is emailed the decision; signing in as
   `suspended_client_demo` now reaches the site normally.
4. Signing in as `suspension_demo_admin` and trying to decide an appeal on a suspension they
   applied is refused.

### 4. Hardening checks

- `php artisan licenses:apply-retention --dry-run` lists abandoned uploads as `abandoned upload`
  (none are seeded; `LicenseRetentionTest` covers it).
- On MySQL, `UPDATE confidential_message_accesses SET reason = 'x'` fails with
  "confidential_message_accesses is append-only".

## Deployment notes

- Three migrations: `requests.responded_at` (with backfill), the append-only triggers, and the
  `suspensions` table. The trigger migration needs MySQL's `log_bin_trust_function_creators=1`
  (set in `docker-compose.yml`) when the app user has no SUPER privilege and binary logging is on.
  The production database needs the same setting before migrating.
- New env var `COUNSELLOR_LICENSE_ABANDONED_RETENTION_DAYS` (default 30).
- New schedule `end-expired-suspensions` (every five minutes) runs in the `queue` service's
  scheduler. Run `php artisan queue:restart` after deploying so workers load the new code.
- After pulling, recreate the db container so it picks up the new flag:
  `docker compose up -d --force-recreate db` (data stays in the volume). Without it, `migrate` fails.
- **Irreversible:** the first nightly retention run deletes the files of abandoned licence uploads.
  Run `php artisan licenses:apply-retention --dry-run` first and review the list; back up
  `storage/app/private/license-documents` if in doubt.
- Rolling back: prefer reverting the code only (old code ignores the new columns and table).
  `migrate:rollback --step=3` deletes every suspension and appeal record.
- The triggers stop row updates and deletes, not `TRUNCATE`/`DROP TABLE`; that guarantee also
  depends on the production app user not being used for ad-hoc DDL.
