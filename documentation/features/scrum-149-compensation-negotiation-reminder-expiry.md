# SCRUM-149 (TT-6.4c, 4/5): Compensation-change reminder + expiry

Fourth of 5 sub-tickets (see `documentation/features/scrum-146-compensation-negotiation-proposal.md`
for the negotiation's overall shape). Ensures silence is never a valid stalling strategy in
either direction: every pending proposal or counter-offer auto-resolves within its window, with a
reminder beforehand.

## What was built

- Two new `AppService` methods, scheduled daily in `routes/console.php` (mirroring
  `purgeExpiredSoftDeletedCounsellors()`'s exact pattern):
  - `sendCompensationRequestExpiryReminders()` — 02:00 daily.
  - `expireStaleCompensationRequests()` — 02:15 daily (after reminders, so the two sweeps never
    race each other for the same request on a boundary day).
- **Reminder**: sent once, when a pending request has ≤2 days left before `expires_at`. Skipped
  entirely if the offer's total window (creation → expiry) was under 3 days — a same-day
  "reminder" adds noise, not value. Exactly-once is enforced with a new `reminder_sent_at`
  timestamp column on `requests` (generic, not compensation-specific, same precedent as
  `expires_at`/`round`), not day-arithmetic alone — robust against the sweep running more or less
  often than expected, unlike a pure `BETWEEN` window check would be.
- **Expiry**: any `pending` `organizationCounsellorCompensationChange` request whose `expires_at`
  has passed auto-resolves to the existing `rejected` status — not a new enum case, functionally
  identical to a manual reject everywhere except a `data['resolvedBy'] = 'expiry'` marker (see
  below). No compensation row created, no affiliation status change — same fairness-critical
  guarantee as SCRUM-147's manual reject, verified again here on an already-active affiliation
  with existing accepted terms behind it, not just a fresh pending one.
- Both sweeps notify whoever the request is currently addressed `to` — a `Counsellor` or (once
  SCRUM-148's counter-offer flips direction) every admin of the `Organization` individually, since
  `Organization` isn't `Notifiable`.
- **SCRUM-150's read-path distinguisher**: since `rejected` is reused for both manual reject and
  auto-expiry, `expireStaleCompensationRequests()` merges `'resolvedBy' => 'expiry'` into the
  request's `data` when auto-resolving it — absent for every manually-resolved request (accept or
  reject). Simple, explicit, and avoids any ambiguity a timestamp-comparison-based signal would
  have introduced.
- **Post-merge hardening** (PR #87 merged before its security review finished; two High findings
  fixed in a follow-up PR): both sweeps now lock and re-check `pending` immediately before writing
  to each row (mirroring SCRUM-147/148's lock-then-recheck pattern), closing a TOCTOU window where
  a concurrent accept/reject/counter-offer could be clobbered back to `rejected`. And a request
  whose recipient no longer resolves (a soft-deleted counterparty, or an organization with no
  admins) now resolves correctly with a logged warning instead of throwing and aborting every
  other pending negotiation's processing for the rest of that day's sweep. See
  `documentation/decision-log.md`.

## How to try it

Backend-only, and these are scheduled jobs rather than user-triggered endpoints. Trigger manually
via tinker:

```php
// Fast-forward a pending request close to expiry, then run the sweep:
$request->update(['expires_at' => now()->addHours(6)]);
App\Services\AppService::new()->sendCompensationRequestExpiryReminders();

// Or past it, to see auto-expiry:
$request->update(['expires_at' => now()->subDay()]);
App\Services\AppService::new()->expireStaleCompensationRequests();
```

Confirm: `$request->fresh()->status` is `REJECTED`, `$request->fresh()->data['resolvedBy']` is
`'expiry'`, the affiliation's status/terms are untouched, and the recipient has a new
notification (check Mailpit at http://localhost:8025 for the email).

## Not yet built (next sub-ticket)

Org-admin negotiation-state read API (SCRUM-150) — the actual consumer of the `resolvedBy` signal
this ticket lays down.

## Testing performed

- New: `tests/Unit/OrganizationCounsellorCompensationReminderExpiryTest.php` (13 tests) — reminder
  fires exactly once for a request within 2 days of expiry (re-running the sweep doesn't
  double-send); no reminder for a too-short window; no reminder while still more than 2 days out;
  expiry auto-rejects with no compensation row/affiliation change and the `resolvedBy` marker set;
  the same fairness guarantee on a renegotiation of an already-active affiliation with existing
  terms; a not-yet-expired request is left alone; an already-resolved request is untouched by
  either sweep; every admin of an org is notified when it's the current recipient; running the
  expiry sweep twice only resolves/notifies once; a request whose recipient no longer exists still
  resolves without blocking an unaffected second request in the same sweep; a request addressed to
  an admin-less organization still resolves without crashing; a malformed `expires_at`/`created_at`
  pair never triggers a reminder; the original proposed terms survive expiry's `data` merge.
- Full suite (final, with SCRUM-148 merged): 604 passed. Pint clean. Migration verified against
  the real dev MySQL database.

## Files changed

- `database/migrations/2026_08_28_700000_add_reminder_sent_at_to_requests_table.php` (new)
- `app/Models/Request.php` — `reminder_sent_at` added to `$fillable`/`$casts`
- `app/Services/AppService.php` — `sendCompensationRequestExpiryReminders()`,
  `expireStaleCompensationRequests()`, and a private `notifyCompensationRequestRecipient()` helper
- `routes/console.php` — two new daily schedule entries
- `app/Notifications/OrganizationCounsellorCompensationChangeExpiryReminderNotification.php` (new)
- `app/Notifications/OrganizationCounsellorCompensationChangeExpiredNotification.php` (new)
- `tests/Unit/OrganizationCounsellorCompensationReminderExpiryTest.php` (new)
