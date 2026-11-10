# Follow-ups, verification documents, how-to step files (SCRUM-379, 301, 194)

This is one PR. The reasoning behind each change is in `documentation/decision-log.md`, under
"SCRUM-379 + 301 + 194".

## What was built

### Anonymity, one rule (SCRUM-379)
Whether someone is shown as "Anonymous" to others is now decided in one place, `isMaskedToOthers()`
on the therapy models. Video names and tokens, chat presence, therapy pages, requests and
notifications all use it.

One case changed: a group's creator who is also one of its counsellors is now masked in calls when
the group is anonymous, matching how the group page already showed them.

### Video (SCRUM-379)
- **Name lookups are cheaper.** Each one reads one membership row instead of the group's whole member
  list.
- **Narrower Daily permissions.** Participants can send audio and video only, never a screen or
  custom tracks, and have no admin rights (no changing others' permissions, no streaming, no
  transcription). Dial-out is off.
- **Unknown sessions.** A name lookup for a session that doesn't exist now gets the same "not in this
  video call" answer as any other refusal.

### Payments and API (SCRUM-379)
- **Paystack webhooks** record charges through the same code as every other charge result.
- **JSON for JSON callers.** Updating, ending and deleting a 1:1 therapy, and changing its payment
  gate, now answer JSON callers with JSON. Pages behave as before.

### Forms and pages (SCRUM-379)
- **Edit forms show the real reason.** When the server refuses a save, the therapy and group edit
  forms, and ending or deleting a therapy, now show why, instead of "Something unfortunate happened".
- **Edit forms show fresh values.** Reopening the edit form after a save shows what was saved, so a
  group just made anonymous no longer reopens unchecked.
- **Session and discussion times** share one set of logic. Editing the end time is no longer snapped
  back; an end less than 30 minutes after the start is refused when you submit.
- **Group chat pages** no longer ask for topics, which only 1:1 therapies have. This removes a
  console error.

### Verification documents (SCRUM-301)
A counsellor's verification request now needs:
- the licensing authority;
- the licence in the form that authority issues it: a copy of the licence (image or PDF), its number,
  or both;
- their national ID number or a copy of the ID.

Before, the server accepted a request with nothing attached.

### How-to step files (SCRUM-194)
A how-to step's image is stored like every other attached file (the shared, tagged file table). A
migration moves existing ones across, and can move them back.

### Dev environment (SCRUM-379)
- The queue and Reverb containers wait until the php container has finished migrating and setting
  storage permissions.
- The seed adds an `Editable Demo Discussion` for trying the update-discussion form.

## How to try it

### Test data
See `documentation/seeded-data.md`. Demo accounts use `password`.

### 1. Edit-form errors
As `maria_garcia`, open a paid group whose counsellors' share is under 70%, edit it, and save. The
alert shows the share rule, not a generic error.

### 2. Discussion times
As `sarah_johnson`, open the Chat Demo Individual Therapy and edit the `Editable Demo Discussion`. Set
the end 10 minutes after the start. The input keeps your value, and saving is refused with "End time
must be at least 30 minutes away from start time…".

### 3. Verification documents
Log in as `unverified_counsellor` and open their counsellor profile (`/counsellor/{id}`). Choose
"request verification", enter a national ID number, and pick "Ghana Psychology Council" (a FILE
authority) without adding the licence file. It's refused with "Upload a copy of your licence".

### 4. Startup order
Run `docker compose up -d --force-recreate php queue reverb`, then `docker compose ps`. php shows
`(healthy)` before queue and reverb start.

## Deployment notes
- **One migration** moves how-to step files onto `fileables` and drops `how_to_steps.file_id`.
  Rollback copies them back.
  - **Rollback order matters.** Run `artisan migrate:rollback --step=1` while the new code is still
    checked out, then switch to the old code. Old code without the column fails to create steps.
- **Daily:** the new token permissions apply from the next join. Dial-out off applies to rooms
  created after the deploy; existing rooms have no dial-out anyway.
- **Docker:** run `docker compose build php queue reverb` (the entrypoint changed), then
  `docker compose up -d --force-recreate php queue reverb`.
- **No new env vars.**
- **Verification requests:** a counsellor who still has the verification form open on an old page
  must now attach the licence document.
