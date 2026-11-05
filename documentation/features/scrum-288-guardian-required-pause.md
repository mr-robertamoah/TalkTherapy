# A minor with no guardian has their therapy paused (SCRUM-288, with follow-ups SCRUM-380)

This is one PR. SCRUM-288 went through `/start-feature`, and the user answered its seven product
questions before any code was written. Every decision is in `documentation/decision-log.md`, under
"SCRUM-380 + 288".

## What was built

### SCRUM-288: therapy paused without an active guardian
A minor needs a guardian to start therapy. Now they also need one to carry on with it. A guardian is
active when the guardianship exists, the guardian's account hasn't been deleted, and that account
isn't suspended. Someone with no date of birth counts as a minor.

**While paused:**
- The minor can still open and read their therapies, messages and sessions.
- Nothing can be written: no messages, sessions, schedule proposals, topics, video, changes to the
  therapy's settings, new therapies or groups, joining a group, or accepting requests. The 1:1
  counsellor is read-only in that therapy too.
- In a group, only the minor's own participation is paused. Everyone else carries on.
  Since SCRUM-381 the group's counsellors are told about a member's pause and resume too, and see
  the paused member (masked if anonymous) in a banner on the group page.
- Upcoming sessions stay scheduled but can't start. Nothing is deleted, ended or refunded.
- Both sides see a calm banner explaining this, with crisis-line wording. The minor's banner links to
  adding a guardian.

**When the last guardian goes** (removing the guardianship, the guardian deleting their account, an
admin deleting the guardian, or the guardian's account being suspended):
- the minor's live call is ended (1:1) or they are taken out of it (group);
- their open pages lock at once, as do their counsellor's;
- the minor and each affected counsellor are notified, in neutral wording, with anonymity respected;
- a deleted guardian's video consents are cancelled.

**When a guardian is back** (a guardianship is accepted or linked, or a suspension is lifted or
expires), everything unlocks on its own and a "resumed" notice is sent. Turning 18 also ends the
pause, and drops the guardian video-consent requirement.

**Safeguarding exception (SCRUM-381):** the super administrator can let a paused minor carry on for
up to 30 days without a guardian -- see `documentation/features/scrum-381-safeguarding-exception.md`.

### SCRUM-380: follow-ups
- **Video:** nobody is a Daily meeting owner any more. The 1:1 counsellor can't start a live stream
  or transcription. An unknown session is answered exactly like a forbidden one on every video route.
- **Anonymity:** message and session ids, and one notification, now use the same anonymity rule as
  the rest, and only ever mask more than before.
- **Controllers:** they all share one success response. Group therapy endpoints and counsellor
  verification answer JSON callers with JSON. Ending a group therapy works again: it had always
  failed.
- **Small fixes:**
  - The session and discussion forms refresh their earliest allowed time each time they open.
  - Group pages no longer offer "create topic".
  - Seeded paid groups can be saved.
  - Only the php container seeds.

## How to try it

### Test data
See `documentation/seeded-data.md` ("Therapy paused for a missing guardian"). Passwords are `password`.
- **`paused_minor`** (Jordan Avery, 15, no guardian), with "Paused Minor Demo Therapy" and a group,
  "Paused Minor Demo Group".
- **`paused_minor_demo_counsellor`**, their counsellor.
- **`paused_minor_demo_group_member`**, an adult in the group.

### 1. Paused, as the minor
Log in as `paused_minor` and open "Paused Minor Demo Therapy". You'll see "This therapy is paused".
History can be read, and there is no message box and no session, topic or video controls.

### 2. Paused, as the counsellor
Log in as `paused_minor_demo_counsellor`. The same therapy shows the counsellor's version of the
banner, and the pending session can't be started. Their group works normally.

### 3. A live pause
In one browser, log in as a minor with one guardian on their therapy page. In another, log in as the
guardian and remove the guardianship from their profile. The minor's page locks without a reload, and
both the minor and the counsellor are notified. Re-establishing the guardianship unlocks it again.

### 4. Who is paused
`docker compose exec -u www-data php php artisan guardians:report-unguarded-minors` lists every paused
account by id. It changes nothing.

## Deployment notes
- **One migration:** an index on `guardianship.ward_id`.
- **Docker:** run `docker compose build php queue reverb` (the entrypoint changed), then
  `docker compose up -d --force-recreate php queue reverb`.
- **No production yet** (user, 2026-11-03). Before any first deploy, run the report command: accounts
  without a date of birth and without a guardian are paused.
- **SCRUM-382 backfill:** after deploying, run
  `docker compose exec -u www-data php php artisan guardians:announce-existing-pauses [--dry-run]` --
  always as the web user, never root (in production: as the web server's user), since it writes the
  scheduler cache. `--dry-run` lists the accounts already paused with something open; without it,
  it takes them out of live calls and tells them and their counsellors, once per account (queued; the
  queue worker must run). An account it couldn't queue is listed, the command exits with 1, and
  running it again retries just those.
- **New queued notifications and broadcast events:** the queue worker and Reverb need to be running.
