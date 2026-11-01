# Privacy and access hardening (SCRUM-367, 263, 273, 335, 95, 144, 307)

One PR, as the user asked ("group the next set of work … into one PR"). The user's decisions
(2026-11-01, "go with the recommendations"):

1. Case discussions are for counsellors only. Clients never see them, live or otherwise.
2. 30 days after an account is closed, its personal details are erased. Messages stay in other
   people's conversations as "Deleted user". Payment, complaint and audit records are kept.
3. A group's payment status (some member's latest payment) is shown only to the group's
   counsellors and to administrators. Members see only their own.

## What was built

### SCRUM-367: older leaks found in the last security review
1. **Rate limits.** Every route with a bare `throttle:N,M` now counts separately, keyed by its
   route name. A bare throttle keys only on the user, so all ~76 such routes used to share one
   counter per person. A few families deliberately share one counter:
   - `session-actions`
   - `image-uploads`
   - `counsellor-update`
   - `message-show`
   - `transactions-initiate`: the three Paystack payment starts
   - `video-provider`: every route that calls the video provider

   A test enforces the rule.
2. **Case discussions are counsellors-only.**
   - `discussion.started`/`discussion.updated` now go to each participating counsellor's own
     private channel (`counsellors.{id}`), never to the therapy's channel, which clients and group
     members join. The therapy page listens there and ignores discussions about other therapies.
   - A counsellor who is only in a discussion can no longer join the client's therapy or group
     channel. Before, their arrival showed the client that a consult existed, and showed the
     consultant the client's roster.
   - "Who takes part in a discussion" now has one definition:
     - the creator, acting as their counsellor profile or their own account;
     - plus every added counsellor.

     It also no longer matches a creator of the wrong type that merely shares an id.
   - The privacy page now says counsellors may consult other counsellors in discussions the client
     doesn't see.
3. **Session updates carry only the session's id** (`{sessionId}`). This covers started, updated,
   topic set and topic unset. Each open page fetches the session from `GET /api/sessions/{id}`
   (`api.sessions.show`), with the same rules as its messages:
   - participants (or anyone, for a public therapy), the strict payment gate, and suspension;
   - a 404 when the session isn't yours to see;
   - an administrator's admin-only fetch is recorded in the read log ("session details") once per
     ten minutes.

   The session-updated notification also carries only the id. Two session notifications that were
   never sent were deleted.
4. **Closed accounts are erased after 30 days.** A daily job (01:30,
   `AppService::anonymiseClosedAccounts`) runs on accounts closed for longer than
   `CLOSED_ACCOUNT_RETENTION_DAYS` (default 30, never below 7). It erases:
   - name, username, email, date of birth, gender, country, preferences and photo (the password is
     replaced);
   - the counsellor profile's name, email, phone, about, photo and cover;
   - the dates of birth inside age and date-of-birth requests, and any identity scan still attached
     (a pending request is closed);
   - the person's notifications, password-reset token, API tokens, the name and email on contact
     messages they sent, and the link from visit records.

   The account row stays and shows as **"Deleted user"**. An account with a suspension that hasn't
   ended (or hasn't started), or with an open complaint about it, is held until that's over. Each run
   logs a count of anonymised, held, retried and failed accounts.

### SCRUM-263: group payment status
`paymentStatus` on a group, and on a group's sessions, is now null for anyone but that group's
counsellors and administrators. Members keep `viewerPaymentStatus` (their own). The UI never showed
the group-wide value to members, so nothing visible changes.

### SCRUM-273: "a member sees update/delete group therapy"
Not a bug. The seeded account QA used (`group_payment_demo_member_paid`) is the group's **creator**,
and the creator may update and delete their group. The server already refuses everyone else, and
a test now proves it for a member who didn't create the group.

### SCRUM-335: video join errors
If the video provider fails while issuing someone's call token, they see "Unable to start the video
call right now. Please try again shortly." (502), never the provider's raw error. The participant
row opened for that join is closed, so they don't count towards the call's size and moderation
doesn't treat them as in the call.

### SCRUM-95: only the app's own error messages reach users
All 64 of the app's exceptions now extend `App\Exceptions\AppException`. A controller shows an
exception's status and message only if it is one of these; anything else (an HTTP client error that
carries an upstream response body, say) becomes a generic 500. An architecture test keeps every
class in `app/Exceptions` on that base.

### SCRUM-144: group participant scopes
`GroupTherapy::whereIsParticipant` now uses `whereParticipant`, the one definition. Both it and
`whereCounsellor` are wrapped so they can't widen a caller's other conditions. The unused, wrong
`whereNotUser` was removed.

### SCRUM-307: therapy cards
Cards show the assigned counsellor's name instead of "No counsellor assigned".

## How to try it

### Test data
See `documentation/seeded-data.md` ("Closed accounts" and "Group payment demo"). Demo accounts use
`password`; super admin `mr_robertamoah` (`SUPER_PASSWORD` in `.env.docker`).

### 1. Live session updates (two browsers)
1. Sign in as a client in one browser and as their counsellor in another, both on the same therapy.
2. As the counsellor, start the session or set its topic. The client's page updates without a
   reload. In devtools, the websocket message has only `sessionId`, followed by a request to
   `/api/sessions/{id}`.

### 2. Closed accounts
1. Reseed (`docker compose exec php php artisan migrate:fresh --seed`, destructive). Two closed
   accounts exist: `closed_account_demo_old` (closed 31 days ago) and `closed_account_demo_recent`
   (closed yesterday).
2. Run the job: `docker compose exec php php artisan tinker --execute="App\Services\AppService::new()->anonymiseClosedAccounts();"`.
3. The old account's names, username and email are now empty and it shows as "Deleted user". The
   recent one is unchanged. `storage/logs/laravel.log` has a "Closed accounts anonymisation run." line.

### 3. Group payment status and actions
1. As `group_payment_demo_member_unpaid` open "Group Payment Demo (Per Therapy)". There is no
   update/delete action, and "pay now" shows.
2. As `group_payment_demo_counsellor`, the payment roster still shows.

### 4. Privacy page
`/privacy`: "Who sees it" lists counsellors consulting each other, and "How long we keep it"
describes the 30-day erasure.

## Deployment notes
- New migration: `users.anonymised_at` (nullable). Safe to roll back with the code. Old code ignores it.
- New env var `CLOSED_ACCOUNT_RETENTION_DAYS` (default 30, floor 7).
- New daily task at 01:30 (`withoutOverlapping`, `onOneServer`). **Erasure can't be undone:** before
  the first run on a database with real data, take a backup and check how many accounts it will hit.
  Production must run the scheduler, or the privacy page's promise isn't kept.
- Rebuild the frontend and restart the queue and Reverb in the same deploy (`scripts/deploy.sh`
  already does), because broadcast payloads changed shape.
- Rate-limit counters start fresh (new keys).
- Behaviour change: a counsellor consulted only in a case discussion can no longer open the client's
  therapy page or join its live channel. They work in the discussion itself.
- `/privacy` still needs a lawyer's review (SCRUM-364).

## Risks / follow-ups
SCRUM-368 tracks what the reviews left for later:
- a public individual therapy's payment status is visible to anyone;
- one owner for the counsellor channel;
- retention periods passed to `/privacy` from config;
- naming.

When the video provider fails on someone's first join, a newly created room stays open with nobody
in it. That isn't a regression: leaving a call already behaves the same way.
