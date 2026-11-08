# Safety flag, part 2: handling, re-alerts and withdrawal (SCRUM-32, TT-4.3b)

This is the second of three PRs. Part 1 (raising a flag and alerting the administrators) is in
`documentation/features/scrum-32a-safety-flag.md`. Part 3 (the notices to the client and guardians,
and closeout) is TT-4.3c. The decisions are in `documentation/decision-log.md`.

It is still behind `SAFETY_FLAGS_ENABLED`, which is off by default.

## What was built

### Handling a flag (the administrator who acknowledged it)
On **Administration → Safety → Safety alerts**, the administrator handling a flag can:
- **Add a note.**
- **Record an action:** contacted the counsellor, the client, a guardian or emergency services; referred
  to a facility; or other. "Other" needs a few words.
- **Resolve it** with an outcome and a note. Both are required. The outcomes are:
  - referred to specialist or inpatient care
  - emergency services attended
  - safety plan in place, therapy continues
  - the client declined
  - couldn't reach the client
  - not warranted
  - other

Resolving a flag frees the person, so another flag can be raised for them.

**Where it's kept:**
- The notes, the action details and the outcome note are encrypted. They're stored apart from the
  history, so they can be cleared when the client's account is anonymised (TT-4.3c).
- **Show the reason** now also shows the notes and actions so far, the counsellor's reason for
  withdrawing (if they did), and the outcome.

Adding a note or an action doesn't open the reason. The new note is added to what's shown only if the
reason is already open. A flag the counsellor withdrew after you took it on stays in your default list
until you resolve it, and a super administrator can still hand it on.

**Every administrator who can see the flag** can open **Show history**: what happened and when (raised,
acknowledged, handed on, note added, action recorded, withdrawn, re-alerted, resolved). It never shows
what was written, and never names the counsellor.

### Re-alerts and the fallback mailbox
- **Re-alerts:** if nobody has acknowledged a flag after **1 hour** (imminent), **4 hours** (urgent) or
  **24 hours** (not urgent), the super administrators who aren't involved get one more email and
  notification ("Still waiting: safety alert…"). It has no name and no reason.
- **How it's sent:** a delayed job when the flag is raised, plus a sweep every five minutes as a backstop.
  Each flag is re-alerted once.
- **If a time is missing or invalid,** the default is used and an error is logged. A bad setting never
  turns re-alerts off.
- **Fallback mailbox:** when there's nobody to tell, **`SAFETY_FALLBACK_MAILBOX`** is emailed instead
  (and it's logged as critical). This applies both when a flag is raised and when it's re-alerted. The
  history records "Fallback mailbox alerted". **Set it before switching the feature on.**

### The counsellor's side
- A therapy, group or help chat page now lists **Safety flags here**: who each is about, its urgency,
  when it was raised, and where it stands:
  - Sent -- waiting for an administrator
  - An administrator is handling it
  - Withdrawn
  - Resolved
- The counsellor never sees the outcome, the notes or who is handling it.
- **Co-counsellors** in a group see the group's flags and their status too, never the reason.
- **Withdraw:** the counsellor who raised a flag can withdraw it while it's open or being handled. They
  give a reason, which only the handling administrator reads.
  - The administrator handling it is told, with no content. If nobody had acknowledged it, the
    administrators who were alerted are told instead.
  - It's never re-alerted afterwards.
  - The handling administrator can still add notes and resolve it.

## How to try it

`SAFETY_FLAGS_ENABLED=true` is set in `.env.docker` (it's on locally). Open http://localhost:8000. The
password for every account is `password`.

1. **Handle a flag.**
   - As `suspension_demo_admin`, open **Safety alerts**. "Safety Flag Demo Group" is a flag you're
     handling.
   - Use **Show the reason**, **Add note**, **Record an action** (try "Other" without text), then
     **Resolve…**.
   - **Show history** lists the steps.
   - As `suspension_demo_normal_admin`, the same flag shows its history, but no notes and no reason.
2. **Withdraw.**
   - As `safety_demo_counsellor`, open "Safety Flag Demo Therapy". Under **Safety flags here**, the open
     imminent flag has **Withdraw**. Give a reason and confirm.
   - In Mailpit (http://localhost:8027), the administrators get "Safety alert withdrawn…", with no
     reason.
3. **Re-alert.**
   - Raise a new imminent flag. To avoid waiting an hour, set `SAFETY_REALERT_MINUTES_IMMINENT=1` in
     `.env.docker` and recreate the php, queue and reverb containers, or run the sweep by hand:
     `docker compose exec -u www-data php php artisan schedule:test --name=realert-safety-flags`.
   - The super administrators get "Still waiting: safety alert…".
4. **Seeded examples:** a withdrawn flag on "Safety Flag Demo (raise one)" and a resolved one on "Safety
   Flag Demo Therapy". Filter the list by **Withdrawn** or **Resolved**.

## Test data

`DatabaseSeeder::createSafetyFlagDemoData` (see `documentation/seeded-data.md`) now seeds:
- an **open** imminent flag (`safety_demo_minor`);
- a flag **being handled** by `suspension_demo_admin` (`safety_demo_member`);
- a **withdrawn** flag (`safety_demo_client`);
- an earlier **resolved** flag (`safety_demo_minor`).

## Deployment notes

- **New migration** `2026_11_16_100000_create_safety_flag_notes_table`. It only adds a table.
- **New environment variables:**
  - `SAFETY_REALERT_MINUTES_IMMINENT`, `SAFETY_REALERT_MINUTES_URGENT` and
    `SAFETY_REALERT_MINUTES_NON_URGENT` (defaults 60, 240 and 1440).
  - `SAFETY_FALLBACK_MAILBOX`. Set it before switching the feature on.
- **New schedule** `realert-safety-flags`, every five minutes. It does nothing while the feature is
  off.
  - **Switching the feature back on** re-alerts every flag that's overdue in the next run.
  - **While it's on,** a missing or invalid `SAFETY_FALLBACK_MAILBOX` is logged as critical once a day.
- **Before going live:** set up external alerting on `/health/scheduler`, critical log lines and
  `failed_jobs`. The re-alert relies on the queue worker.
- **Rolling back the code** leaves delayed `ReAlertSafetyFlagJob` jobs behind. Delete them from `jobs`
  first. Rolling back the migration drops every note.
- **Release gate:** the feature still needs the Ghana Act 846/843 legal read and TT-4.3c before it's
  switched on.

## Not in this PR (TT-4.3c)

- The client's and guardians' notices (held for the administrator).
- Clearing the notes and other free text on account anonymisation.
- An open flag keeping a help chat past its one-year deletion.
- /privacy.

## Tests

`tests/Feature/SafetyFlagHandlingTest.php`, plus `tests/Feature/SafetyFlagTest.php`.
