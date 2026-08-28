# SCRUM-147 (TT-6.4c, 2/5): Accept/reject compensation-change proposals

Second of 5 sub-tickets (see `documentation/features/scrum-146-compensation-negotiation-proposal.md`
for the negotiation's overall shape and history). This one closes the loop 1/5 opened: the
counsellor a proposal is addressed to can now accept or reject it.

## What was built

- New `RespondToOrganizationCounsellorCompensationRequestAction`, wired into
  `RespondToRequestAction`'s existing per-type dispatch chain (same lock-for-update-then-transition
  shape as `RespondToOrganizationCounsellorRequestAction`).
- **Accept**: creates the real `organization_counsellor_compensations` row via the unchanged
  `CreateOrganizationCounsellorCompensationAction`, using the terms carried in `Request.data`.
  Attributed to the *original proposer* (stored as `proposedById` in `Request.data` since
  SCRUM-146's `ProposeOrganizationCounsellorCompensationChangeAction` was extended to record it),
  not whoever clicks accept — matters once a counter-offer (SCRUM-148) can flip who proposed last.
  Activates the affiliation if this is its first-ever compensation row (existing, unchanged rule).
- **Reject**: a flat decline. `Request` → `rejected` (no new status). No compensation row. The
  affiliation's status and any existing terms are left completely untouched, at any round, in
  either direction — verified with a dedicated regression test on an already-active affiliation
  with existing accepted terms behind it, not just a fresh pending one.
- The proposer is notified either way (`OrganizationCounsellorCompensationChangeAcceptedNotification`
  / `...RejectedNotification`, in-app + email gated on `email_verified_at`).
- **No new authorization action was needed.** The ticket's plan called for a bespoke
  `EnsureUserCanRespondToOrganizationCounsellorCompensationRequestAction`, but the existing generic
  `EnsureUserCanRespondToRequestAction` (already run ahead of every request type's dispatch) already
  authorizes exactly the counsellor a proposal is addressed to, and rejects everyone else —
  confirmed empirically rather than assumed; see `documentation/decision-log.md`.
- **Accept now has two eligibility guards**, both raising a clean `OrganizationException` (request
  stays `pending`, so it can still be resolved another way): the affiliation must not have `ended`
  since the proposal was made, and the original proposer's account must still exist (`set_by_id`
  is never silently written as `NULL`). Reject is unaffected by either guard — a decline must
  always succeed. Both were review findings on PR #85, fixed before merge.
- **Proposal creation is now concurrency-safe per affiliation** — `proposeCompensationChange()`
  locks the affiliation row for the duration of its "no pending request already exists" check,
  closing a TOCTOU race flagged during SCRUM-146's review and explicitly assigned to this ticket.

## How to try it

Still backend-only — no UI (TT-6.5a doesn't exist yet). Continue from SCRUM-146's tinker example:
after `proposeCompensationChange(...)` returns `$request`, respond to it as the counsellor:

```php
App\Services\RequestService::new()->respondToRequest(
    App\DTOs\RequestResponseDTO::new()->fromArray([
        'user' => $counsellor->user,
        'request' => $request,
        'response' => 'accepted', // or 'rejected'
    ])
);
```

Confirm: `$affiliation->currentCompensation()` now returns the proposed terms (on accept) or
`null`/the prior terms unchanged (on reject); `$affiliation->status` is `active` only on accept
of a first-ever proposal; the org admin who proposed receives the resolution notification (check
Mailpit at http://localhost:8025).

## Not yet built (later sub-tickets)

Counter-offer (SCRUM-148), reminder/expiry sweep (SCRUM-149), org-admin negotiation-state read API
(SCRUM-150).

## Testing performed

- New: `tests/Unit/OrganizationCounsellorCompensationResponseTest.php` (12 tests) — accept creates
  the compensation row and activates a pending affiliation; accept on an already-active affiliation
  adds a row without re-activating; reject creates nothing and changes nothing, including on a
  renegotiation of an already-active affiliation with existing terms; an outsider and the
  proposing admin themselves are both rejected; responding twice to an already-resolved request is
  a no-op; the response renders correctly through `OrganizationRequestResource`; accept/reject
  behavior when the original proposer no longer exists; accept/reject behavior against an
  affiliation that has since ended.
- Full suite: 578 passed (up from 566). Pint clean.
- The affiliation-row-lock concurrency fix cannot be exercised by an automated test (the suite
  runs against sqlite `:memory:`, which has no real concurrent-transaction semantics) — correctness
  rests on documented MySQL InnoDB `SELECT ... FOR UPDATE` behavior instead; see decision-log.md.

## Files changed

- `app/Actions/Request/RespondToOrganizationCounsellorCompensationRequestAction.php` (new)
- `app/Actions/Request/RespondToRequestAction.php` — dispatch entry for the new type
- `app/Actions/Organization/ProposeOrganizationCounsellorCompensationChangeAction.php` — records
  `proposedById` in `Request.data`
- `app/Services/OrganizationCounsellorCompensationService.php` — affiliation-row lock around
  proposal creation's pending-check
- `app/Notifications/OrganizationCounsellorCompensationChangeAcceptedNotification.php` (new)
- `app/Notifications/OrganizationCounsellorCompensationChangeRejectedNotification.php` (new)
- `tests/Unit/OrganizationCounsellorCompensationResponseTest.php` (new)
