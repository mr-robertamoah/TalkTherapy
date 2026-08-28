# SCRUM-148 (TT-6.4c, 3/5): Counter-offer for compensation-change proposals

Third of 5 sub-tickets (see `documentation/features/scrum-146-compensation-negotiation-proposal.md`
for the negotiation's overall shape). This is the genuinely novel piece: the party a proposal is
addressed to can counter with different terms instead of only accepting or flatly rejecting.
Counter-offering **is** how a counsellor (or org) constructively disputes terms — there is no
separate reason-field or mediation-escalation path, per the original product decision.

## What was built

- New `OrganizationCounsellorCompensationService::counterOffer()` / `CounterOfferOrganizationCounsellorCompensationChangeAction`.
  Atomically, in one lock-for-update transaction: supersedes the current pending request (status
  → `rejected` — not a new enum value, the same flat-decline semantics as an outright reject) and
  creates a new `organizationCounsellorCompensationChange` request in the **reverse direction**,
  referencing the same affiliation, with `round` incremented from the parent and its own
  (possibly overridden) `expiryDays`.
- **Direction flips symmetrically**: the org's proposals address `to` = the `Counsellor`; a
  counsellor's counter-offer addresses `to` = the `Organization` itself (not a specific admin) —
  mirroring how the org's own turn already works. This means **any** admin of the organization can
  respond to a counter-offer, not just whichever admin made the original proposal, and it lets the
  existing `EnsureUserCanRespondToRequestAction`'s `Organization`-administered-by branch (already
  in the codebase, previously unused by this feature) handle authorization for the org's turn with
  zero new code.
- **No new authorization action was needed** — same finding as SCRUM-147. Counter-offering reuses
  `EnsureUserCanRespondToRequestAction` (via a small inline `RequestResponseDTO`, since it expects
  that shape) since "may counter-offer" and "may accept/reject" are the same underlying
  permission: being the current `to`-party of a pending request.
- **Round cap**: `config('organization.compensation_negotiation_max_rounds')` (default 5).
  Attempting to counter past it throws a clean error; accept/reject remain available on the
  capped-out request.
- The proposal-created notification (`OrganizationCounsellorCompensationChangeProposedNotification`,
  from SCRUM-146) was generalized rather than duplicated: its wording no longer assumes the
  organization is always the one proposing, and it now resolves a display name correctly for
  either a `Counsellor` or a `User` (org admin) notifiable. Since `Organization` isn't itself
  `Notifiable`, a counter-offer addressed to it notifies every one of its admins individually
  (`Notification::send($organization->admins, ...)`).
- The "one pending request per affiliation" invariant (SCRUM-146/147) holds across an arbitrarily
  long counter-offer chain, since the counter-offer action always resolves the current request in
  the same transaction it creates the next one — verified by test, not just by construction.

## How to try it

Still backend-only. Continuing the tinker flow from SCRUM-146/147: once `$request` is pending,
the counsellor can counter instead of accept/reject:

```php
$counterOffer = App\Services\OrganizationCounsellorCompensationService::new()->counterOffer(
    App\DTOs\OrganizationCounsellorCompensationDTO::new()->fromArray([
        'user' => $counsellor->user,
        'request' => $request,
        'type' => App\Enums\OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 7500,
        'currency' => 'GHS',
    ])
);
```

Confirm: `$request->fresh()->status` is now `REJECTED`; `$counterOffer->round` is `2`;
`$counterOffer->from_type`/`to_type` have swapped (`Counsellor` → `Organization`); any admin of
the organization can now accept/reject/counter `$counterOffer` in turn. HTTP:
`POST /requests/{requestId}/compensation-counter-offer` (new route), same body shape as the
propose endpoint (`type`/`amount`/`currency`/`percentage`/`basis`/`expiryDays`).

## Not yet built (later sub-tickets)

Reminder/expiry sweep (SCRUM-149), org-admin negotiation-state read API (SCRUM-150).

## Testing performed

- New: `tests/Unit/OrganizationCounsellorCompensationCounterOfferTest.php` (11 tests) — basic
  counter-offer creates the reverse-direction request correctly; the one-pending-invariant holds
  across a counter; a multi-round chain (propose → counter → counter) correctly attributes the
  eventual accepted compensation row to whoever proposed the *last* round, not whoever proposed
  first or whoever clicked accept; the round cap (config-driven, tested via a config override, not
  the real default) blocks a further counter but not accept/reject; authorization (outsider,
  proposer-cannot-counter-own-proposal); countering an already-resolved request; invalid terms;
  a non-existent request; every admin of the org (not just the one who proposed) can be notified.
- Full suite: 589 passed (up from 578). Pint clean.

## Files changed

- `app/Actions/Organization/CounterOfferOrganizationCounsellorCompensationChangeAction.php` (new)
- `app/Services/OrganizationCounsellorCompensationService.php` — `counterOffer()` added
- `app/DTOs/OrganizationCounsellorCompensationDTO.php` — new `request` field
- `app/Notifications/OrganizationCounsellorCompensationChangeProposedNotification.php` —
  generalized wording/notifiable-name resolution for reuse across both directions
- `app/Http/Controllers/OrganizationCounsellorCompensationController.php` — `counterOffer()` action
- `routes/web.php` — `POST /requests/{requestId}/compensation-counter-offer`
- `tests/Unit/OrganizationCounsellorCompensationCounterOfferTest.php` (new)
