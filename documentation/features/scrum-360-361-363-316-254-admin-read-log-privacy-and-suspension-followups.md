# Admin read log, confidential switch and privacy page, suspension follow-ups (SCRUM-360, 361, 363, 316, 254)

One PR, as the user asked. The user's decisions (2026-11-01): admins keep reading ordinary
messages but every read is recorded; the log is kept forever; build the confidential switch now;
we write the privacy page, marked for legal review; payouts are held during a suspension and
counsellors are told; deleting your own account is blocked while suspended.

## What was built

### SCRUM-360: admin read log
- Any time an administrator reads content they can only reach **because they are an
  administrator**, one row goes into `admin_content_accesses`: who, what, when, page, IP and
  device. That covers a private therapy's or group's page (its background story), session
  messages, topic messages, a single message and its replies, message attachments, a discussion's
  messages, licence documents and identity documents.
- Message search no longer matches text the searcher can't see: a confidential message they
  didn't send or receive, or one deleted for everyone, never matches (security review).
- Not recorded: participants reading their own conversations, anyone reading a public therapy, an
  admin opening their own documents, and confidential reads during a complaint review (those are
  already recorded on the report).
- One row per fetch (a page of messages, one file), never one per message. An attachment is
  recorded at most once per admin per ten minutes, since a chat page loads every attachment in it.
- The log is append-only: the model refuses updates and deletes, and so do database triggers
  (MySQL and SQLite).
- Super admins see it at `/administrator/content-accesses` ("read log" in the admin menu), filtered
  by kind of content or by administrator.

### SCRUM-361: confidential switch and privacy page
- The chat message box (individual and group therapies) has a **Confidential** checkbox. It only
  appears when the message goes to one person: in an individual therapy always; in a group, when
  a member writes to its single counsellor or a counsellor writes to the group's creator. While it
  is ticked, a line under the box explains who can read it and that an administrator may read it
  if the conversation is reported, with a link to the privacy page.
- The server refuses a confidential message with no single recipient (422), since only its sender
  could ever read it.
- `/privacy` describes what the app actually does: confidential messages and complaint reviews,
  what administrators can see and how it is recorded, verification-document retention, and
  suspensions. It is marked **"not yet reviewed by a lawyer"**. The footer's Privacy link opens it,
  and it is readable signed out and by a suspended account.

### SCRUM-363: suspension follow-ups
1. **Live connections: not closed server-side (deferred, SCRUM-366).** Reverb v1.10's terminate-user
   API fails whenever a connection's first channel is a private one, so it can't be relied on; an
   attempt was built, tested and removed. As before, the person's open pages are sent to
   `/suspended` straight away and every request and reconnect is refused. A deliberately modified
   browser can keep receiving updates on subscriptions it already has open.
2. **Video.** After a suspension is saved, the video rooms of the sessions it ended are closed, and
   the person is taken out of any other live room: every room for a whole-account suspension, rooms
   they are in as counsellor for a counsellor-role one. Removal reuses the same steps as a
   counsellor removing someone (`EjectVideoParticipantAction`).
3. **Payouts held.** A suspended counsellor can't withdraw, and an admin can't pay out on their
   behalf. A payout requested just before the suspension waits, still pending, and is sent once
   the suspension ends, is lifted, or an appeal succeeds. Their earnings stay pending and can be withdrawn once the suspension ends. They're told in
   the suspension email, on their payout section ("on hold until …") and on `/suspended`.
4. **No self-deletion while suspended.** Deleting your own account, or your own counsellor profile,
   is refused while any suspension is active, including an admin deleting themselves through the
   admin panel. An administrator deleting someone else is unaffected.
5. **Topics and notes.** A suspended counsellor can't change session topics or write session or
   message notes. Reading still works.
6. **Guardians.** A guardian of any member of a reported group counts as part of the report.
7. **Live session status.** Sessions a suspension cancels or ends are broadcast, so open pages update.
8. **Search.** `%`, `_` and `!` in user, counsellor and discussion searches are matched literally,
   and an empty or blank user search returns nobody (it used to return everyone).
9. **Purge note.** Recorded in the decision log: the `suspensions` foreign keys restrict deletes,
   so a future hard-delete or GDPR purge must deal with those rows first.
10. **MySQL in CI.** A new CI job runs the migrations and the append-only trigger tests on MySQL 8
    (`phpunit.mysql.xml`).

### SCRUM-316: seeded sessions
Every seeded session is linked to its therapy or group through one helper (`seedSession()`, using
`for()->associate()`), the same way the app creates sessions. `Session::$fillable` stays without
`for_id`/`for_type`, and a test pins that.

### SCRUM-254: links injected into emails
Laravel's secured Markdown encoding is switched on for every email. Anything a user typed (names in
the greeting, reasons, notes) can no longer become a link, an image or an HTML tag. Plain text
still reads exactly as typed. The three old per-reason escapes were removed, since escaping twice
would undo the protection.

## How to try it

Reseed first (destructive to local data):

```bash
docker compose exec php php artisan migrate:fresh --seed
docker compose up -d --force-recreate db   # once, if the db container predates SCRUM-362
```

### Test data
See `documentation/seeded-data.md` ("Suspension demo" and "Admin read log"). Super admin:
`mr_robertamoah` (password in `.env.docker` as `SUPER_PASSWORD`); demo accounts use `password`.

### 1. Read log
1. As `mr_robertamoah`, open `/administrator/content-accesses`: three seeded entries are listed.
2. Open any private therapy's chat you aren't part of, then reload the read log: a
   "session messages" entry for you appears. Opening a public therapy adds nothing.
3. As `suspension_demo_normal_admin`, the page sends you home.

### 2. Confidential switch
1. Sign in as `maria_garcia` and open one of her individual therapies' chat.
2. Tick **Confidential**: the disclosure line appears with a "Learn more" link.
3. Send the message: it shows as confidential. In a discussion there is no checkbox.

### 3. Privacy page
Open `/privacy` signed out, and from the footer: the confidential-messages section is at
`/privacy#confidential-messages`.

### 4. Suspension follow-ups
1. As `suspended_counsellor_demo`, open your counsellor profile: the payout section says payouts
   are on hold until the suspension ends. Trying to delete your account says you can't while
   suspended.
2. Two browsers: sign in as a demo client in one; in the other, as `mr_robertamoah`, suspend that
   client's whole account from a resolved "suspension recommended" report. The first browser goes
   to `/suspended`.

## Deployment notes
- New migration: `admin_content_accesses` with append-only triggers. Like SCRUM-362, MySQL with
  binary logging needs `log_bin_trust_function_creators=1` (see README) before migrating.
- No new env vars, no new schedules.
- `/privacy` must be reviewed by a lawyer before it reaches production (follow-up ticket).
- Run `php artisan queue:restart` after deploying (queued mail picks up the secured encoding), and
  rebuild the frontend. Broadcasts to Reverb now time out after 3 seconds (`config/broadcasting.php`).
- Don't roll back the new migration in production: `down()` drops the read log. Revert the code
  only; the old code ignores the table.
