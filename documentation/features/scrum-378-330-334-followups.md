# Refund retry lookup, video join hardening, tidy-ups (SCRUM-378, 330, 334)

This is one PR. The reasoning behind each change is in `documentation/decision-log.md`, under
"SCRUM-378 + 330 + 334".

## What was built

### Payments (SCRUM-378)
- **Refund retries look up the result.** A refund now remembers Paystack's refund id. If a retry is
  refused, the job asks Paystack about that refund, and records it only when Paystack says it's
  processed or failed and the details match. A first attempt that timed out before Paystack answered
  still waits for Paystack's webhook.
- **One way to read Paystack charge results.** Organisation charges, retainer settlements and
  "verify" checks all go through `RecordPaystackChargeOutcomeAction`.
- **Lookups are labelled.** Statuses found by a retry lookup are recorded as `RETRY_LOOKUP` in the
  transaction, payout and refund histories.

### Video
- **Joins and removal retries don't overlap (SCRUM-378).** A per-person lock makes sure a removal
  retry can't throw out someone who has just been let back in.
- **Chime calls don't fill up with reconnects (SCRUM-330).** The 25-person limit counts people, not
  connections, and someone already in a full call can reconnect.
- **Making a group anonymous can't race a join (SCRUM-334).** Either the join is seen and the change
  is refused, or the join is masked.
- **Names in calls come from us (SCRUM-334).** Daily lets anyone rename themselves in their own
  browser, so an anonymous member could have shown up as a counsellor's name. The call now asks our
  server for each person's label instead.
- **Daily rooms are explicit (SCRUM-334).** No screen share, no lobby (knocking), no chat, and
  recording never enabled.

### Tidy-ups (SCRUM-378)
- Session and discussion forms share one set of time helpers, and both check 30 minutes ahead.
- The country list is cached as JSON, so names with commas work.
- Every scheduled job has a name in `php artisan schedule:list`.
- The unused `app/Console/Kernel.php` is gone.
- Storage permissions are set once, by the php container.

## How to try it

### Test data
See `documentation/seeded-data.md`. Demo accounts use `password`.

### 1. Form checks
On a therapy, open Create Discussion and set the start 10 minutes ahead. It is refused with "Start
time must be at least 30 minutes away…", the same as sessions.

### 2. Video labels
This needs Daily or Chime credentials (SCRUM-317). In a group call, each tile shows our label. For
an anonymous member that's "Anonymous", even if they rename themselves in Daily. Without credentials,
`GET /sessions/{id}/video/participants/{key}` returns 404 "That participant is not in this video
call." for anyone not in a call.

### 3. Schedules
`docker compose exec php php artisan schedule:list` lists every job by name, at the same times as before.

## Deployment notes
- **Two migrations:**
  - `refunds.paystack_refund_id` (nullable);
  - `RETRY_LOOKUP` added to the three status-history `source` enums (MySQL only; the down migration
    is a no-op, like earlier enum additions).
- **Docker: rebuild the images.** Run `docker compose build php queue reverb`, then
  `docker compose up -d --force-recreate php queue reverb`. The php entrypoint is baked into the
  image, so recreating alone keeps the old one.
- **Production:** normal deploy. `deploy.sh` runs the migrations and `queue:restart`.
- The stuck-charge alert's once-a-day key changed, so one extra alert may be logged on deploy day.
