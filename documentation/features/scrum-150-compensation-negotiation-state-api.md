# SCRUM-150 (TT-6.4c, 5/5): Org-admin compensation negotiation-state API

Fifth and final sub-ticket of TT-6.4c (see `documentation/features/scrum-146-compensation-negotiation-proposal.md`
for the negotiation's overall shape). Backend-only — the admin dashboard (TT-6.5a) that would
render this hasn't been built and has no sub-tickets filed yet. This lays the read API groundwork
for that.

## What was built

- New `OrganizationCounsellorCompensationService::getNegotiationState()` — returns the latest
  `organizationCounsellorCompensationChange` `Request` for an affiliation regardless of status
  (ordered by round, then id, descending), or `null` if none has ever existed. Reuses the exact
  same view-authorization gate as SCRUM-123's `getCompensations()`
  (`EnsureUserCanViewOrganizationCounsellorCompensationsAction` — org admin or the affiliated
  counsellor themselves), since both are read paths over the same affiliation.
- New `OrganizationCounsellorCompensationNegotiationStateResource`, with a `state` discriminator:
  - `'none'` — no negotiation has ever existed for this affiliation.
  - `'pending'` — the latest round is awaiting a response; includes `from`/`to` (so the direction
    is explicit — an `Organization` or a `Counsellor`), `round`, `proposedTerms`, and `expiresAt`.
  - `'resolved'` — the latest round is `accepted` or `rejected`. For `rejected`, a `resolvedBy`
    field (`'response'` or `'expiry'`, from SCRUM-149's signal) distinguishes a manual decline
    from an unanswered auto-expiry, per AC3.
- New read-only endpoint: `GET /organization-counsellors/{organizationCounsellorId}/compensations/negotiation-state`.
- **Zero changes to the existing accepted-terms history read** (`index()` /
  `OrganizationCounsellorCompensationService::getCompensations()`, SCRUM-123) — this is a
  completely separate, additive query. Verified by a test that a pending negotiation never shows
  up in `getCompensations()`'s results.

## How to try it

Still backend-only. Via tinker, once an affiliation has some negotiation history:

```php
$state = App\Services\OrganizationCounsellorCompensationService::new()->getNegotiationState(
    App\DTOs\OrganizationCounsellorCompensationDTO::new()->fromArray([
        'user' => $owner, // or the affiliated counsellor's own user
        'organizationCounsellor' => $affiliation,
    ])
);

(new App\Http\Resources\OrganizationCounsellorCompensationNegotiationStateResource($state))->toArray(request());
```

Or via HTTP: `GET /organization-counsellors/{organizationCounsellorId}/compensations/negotiation-state`
as an authenticated org admin or the affiliated counsellor.

## Files changed

- `app/Services/OrganizationCounsellorCompensationService.php` — `getNegotiationState()` added
- `app/Http/Resources/OrganizationCounsellorCompensationNegotiationStateResource.php` (new)
- `app/Http/Controllers/OrganizationCounsellorCompensationController.php` — `negotiationState()`
  action added; `index()`/`store()`/`counterOffer()` completely unchanged
- `routes/web.php` — new `GET .../compensations/negotiation-state` route
- `tests/Unit/OrganizationCounsellorCompensationNegotiationStateTest.php` (new)

## Testing performed

- New: `tests/Unit/OrganizationCounsellorCompensationNegotiationStateTest.php` (13 tests) — no
  history → `'none'`; a pending org-initiated proposal reports its direction/terms; a pending
  counter-offer reports the reversed direction and the correct (latest) round; a manual reject and
  an auto-expiry are distinguishable; an accepted negotiation is reported resolved and a fresh
  proposal is always startable afterwards; the affiliated counsellor (not just the org admin) can
  also view it; an outsider cannot; a non-existent affiliation returns a clean error; a pending or
  resolved negotiation never leaks into `getCompensations()`'s accepted-terms history; three
  resource-level tests verify the actual serialized JSON shape for `'none'`, `'pending'`, and an
  expiry-distinguished `'resolved'` case.
- Full suite: 612 passed. Pint clean.
