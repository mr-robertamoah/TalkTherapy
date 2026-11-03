# Sweeps, Paystack retries, storage, forms, payment-data audit, video removals (SCRUM-376, 377, 141, 315)

This is one PR. The reasoning behind each change is in `documentation/decision-log.md`, under
"SCRUM-376 + 377 + 141 + 315".

## What was built

### SCRUM-376: follow-ups from the ops and architecture work
- **Scheduled sweeps moved out of `AppService`.** Every sweep now has its own Action under
  `app/Actions/<Domain>` (visitors, the daily status alert, counsellor purge, closed-account
  anonymisation, identity documents, compensation reminders and expiry, invoice settlement, guardian
  video-consent reminders, idle speaking grants). The schedule itself is unchanged.
- **Paystack retries look up the result.** When a payout or a retainer settlement is retried and
  Paystack answers 4xx (for example "duplicate reference"), the job asks Paystack what actually
  happened to that reference. It records a definite answer, and otherwise leaves the record for
  follow-up as before. Refunds are unchanged, because Paystack offers no documented way to look a
  refund up by its transaction.
- **Storage is no longer world-writable.** The queue and Reverb containers no longer run
  `chmod -R 777` on storage, so private uploads (identity, licence and report-evidence documents) are
  owned by www-data and not writable by everyone.
- **Fewer repeated alerts.** A stuck organisation charge's "follow up by hand" warning is logged once
  a day, not every 15 minutes.

### SCRUM-377: form bugs
- **Default session times pass the 30-minute rule.** The create forms' default start is at least
  31 minutes ahead, rounded up to the next 5 minutes, and the default end is 40 minutes after the
  start. The session forms' own check now matches the server's 30 minutes.
- **The real message shows.** A start under 30 minutes away now says "The start time has to be at
  least 30 minutes from now.", instead of "The start time field is prohibited."
- **No country-flag 404.** The profile, admin-user and licensing-authority forms share one country
  list. It only calls the countries API when `VITE_COUNTRIES_API` is a real URL, and otherwise uses the
  built-in list.

### Also fixed (found in browser QA)
- **Fresh defaults.** The session and discussion forms set their default times each time they open, so
  a therapy page left open for a while no longer offers a start that's already too soon.
- **Update-session form.** It no longer crashes on open (an undefined variable in its in-person map
  watcher).
- **Reverb.** It starts again after a container recreate: its bind-mounted entrypoint now runs through
  `bash`.

### SCRUM-141: payment-data audit
`php artisan payments:audit-paid-therapies` lists paid therapies and group therapies whose payment
settings (amount, currency or per) were wiped by the bug fixed in SCRUM-140, with each one's owner. It
changes nothing, and it exits with code 1 when it finds any. Ask the owners to set their price again.

### SCRUM-315: video removals the provider didn't confirm
Removing someone from a group video call still takes effect here at once. If the video service fails
to eject them:
- the counsellor sees an amber notice: "Participant removed here, but the video service has not
  confirmed it yet. Retrying…";
- the server retries the eject in the background (4 attempts over about two minutes), and tells the
  admins through the failed-job alert if it never succeeds;
- the retry stops if the call has ended, or if the person has since been let back in.

## How to try it

### Test data
See `documentation/seeded-data.md`. Demo accounts use `password`, and the super admin is
`mr_robertamoah` (`SUPER_PASSWORD` in `.env.docker`).

### 1. Session form defaults
Open a therapy you counsel and create a session. The start defaults to a 5-minute mark at least
31 minutes from now, and submitting it straight away isn't refused for being too soon. Set a start
10 minutes ahead: the form refuses it with "Start time must be at least 30 minutes away…".

### 2. Profile countries
Open `/profile` and click to update. The network tab has no `/123/countries/flag/images` request, the
console has no error, and the country list still opens.

### 3. Payment-data audit
`docker compose exec php php artisan payments:audit-paid-therapies` prints "No paid therapy or group
therapy is missing its payment settings." on a normal seed, and exits with code 0.

### 4. Video removal
This needs a real Daily or Chime sandbox (see SCRUM-317). With the provider unreachable, removing a
participant shows the amber notice, and the queue log shows `RetryVideoParticipantRemovalJob`
attempts.

## Deployment notes
- **No migrations, and no new env vars.**
- **Recreate the `php`, `queue` and `reverb` containers** (`docker compose up -d --force-recreate php
  queue reverb`) to pick up the new command and the shared `docker/php/storage-permissions.sh`.
  Editing the compose file alone changes nothing. Afterwards:
  - `ls -la storage storage/app/private` shows owner www-data, your group, no world-write, and no
    world access under `private`;
  - `storage/logs/laravel.log` keeps updating (the scheduler heartbeat writes every minute).
- **Production** deploys with `scripts/deploy.sh`, not Compose, so nothing changes there.
- **Don't use the audit as a deploy gate.** It exits 1 for data that only the owners can fix.
- **Restart the queue** so the scheduler loads the new sweep actions and the worker picks up the new job.
- **Run `php artisan payments:audit-paid-therapies` once** in each environment, and contact the
  owners of any rows it lists.
