# SCRUM-146 (TT-6.4c, 1/5): Compensation-change proposal creation

First of 5 sub-tickets converting compensation-terms changes for an org-counsellor affiliation
from an org admin's unilateral, immediately-effective write into a two-party negotiation. This
sub-ticket builds the foundation: the org admin can now only *propose* a change; a counsellor's
consent (SCRUM-147, next) is required before anything actually takes effect.

See `documentation/decision-log.md` (2026-08-28 entries, SCRUM-131/146) for the full negotiation
history — this started as "add a notification" (SCRUM-131) and grew, through explicit user
decisions, into a full negotiation feature with counter-offers and a reminder/expiry mechanism,
split into 5 sub-tickets tracked as **TT-6.4c** in `documentation/implementation_plan.md`.

## What was built

- New `RequestTypeEnum::organizationCounsellorCompensationChange`, reusing the existing `Request`
  accept/reject infrastructure (same pattern as org-counsellor affiliation invites, group-therapy
  membership, guardianship) rather than a bespoke mechanism. `for` is the `OrganizationCounsellor`
  affiliation itself, not the `Organization` directly — this is what lets one negotiation thread
  be uniquely identified regardless of which direction it's currently pending in (relevant once
  SCRUM-148's counter-offer flips `from`/`to`).
- New generic, reusable columns on `requests`: `expires_at` (nullable timestamp) and `round`
  (nullable tinyint) — not compensation-specific, usable by any future request type needing an
  expiry mechanism.
- `OrganizationCounsellorCompensationService::setCompensation()` (the old unilateral write) has
  been **removed**, not just deprecated — leaving it reachable would have been a live bypass of
  this feature's entire purpose. New `proposeCompensationChange()` takes over its
  authorization/validation guarding but creates a `Request` instead of writing directly to
  `organization_counsellor_compensations`. That table's schema and
  `OrganizationCounsellor::currentCompensation()`'s resolution logic are completely untouched —
  they only ever see already-accepted terms (SCRUM-147's job).
- An org admin can optionally override the negotiation window's expiry (1–30 days,
  `config('organization.compensation_negotiation_default_expiry_days')`, default 7) per proposal.
- The counsellor receives an in-app + email notification on every new proposal
  (`OrganizationCounsellorCompensationChangeProposedNotification`).
- Only one pending negotiation is allowed per affiliation at a time
  (`EnsureNoPendingOrganizationCounsellorCompensationRequestAction`).
- Fixed a resource bug this new type would otherwise have hit: `OrganizationRequestResource`
  assumed `for` was always an `Organization` — it now resolves through `for->organization` when
  `for` is an `OrganizationCounsellor` affiliation instead.

## Not yet built (later sub-tickets)

- **Accept/reject** (SCRUM-147) — right now a proposal can be created but there is no way to
  respond to it yet; it will simply sit `pending` until SCRUM-147 ships.
- **Counter-offer** (SCRUM-148), **reminder/expiry sweep job** (SCRUM-149, the `expires_at` set
  here isn't enforced by anything yet), **org-admin negotiation-state read API** (SCRUM-150).

## How to try it

There is no UI for this yet (the org admin dashboard, TT-6.5a, hasn't been built — this whole
epic, TT-6, currently has **no** seeded demo data or browser-reachable pages at all, a pre-existing
gap across all of TT-6.1–6.4b, not something introduced by this ticket). Verify via:

- **Pest**: `docker compose exec php php artisan test --filter=OrganizationCounsellorCompensation`
- **Tinker**, end-to-end against a real affiliation:
  ```php
  $org = App\Models\Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
  $owner = App\Models\User::factory()->create();
  $org->admins()->attach($owner->id, ['role' => App\Enums\OrganizationAdminRoleEnum::owner->value]);
  $counsellor = App\Models\Counsellor::factory()->create(['user_id' => App\Models\User::factory(), 'verified_at' => now()]);
  $affiliation = App\Models\OrganizationCounsellor::factory()->create(['organization_id' => $org->id, 'counsellor_id' => $counsellor->id]);

  $request = App\Services\OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
      App\DTOs\OrganizationCounsellorCompensationDTO::new()->fromArray([
          'user' => $owner,
          'organizationCounsellor' => $affiliation,
          'type' => App\Enums\OrganizationCounsellorCompensationTypeEnum::fixed->value,
          'amount' => 5000,
          'currency' => 'GHS',
      ])
  );
  ```
  Confirm `$request->status === 'PENDING'`, `$affiliation->compensations()->count() === 0`, and
  check Mailpit (http://localhost:8025) for the counsellor's notification email.
- **HTTP**: `POST /organization-counsellors/{organizationCounsellorId}/compensations` (existing
  route, unchanged) as an authenticated org admin — now returns the created pending `Request`
  instead of a compensation resource.

### Follow-up filed

The complete absence of seeded data and feature docs across the entire Organizations epic (TT-6)
is a pre-existing gap, out of scope to retroactively fix in this ticket — flagging here rather than
silently leaving it unrecorded, for whoever picks up TT-6.5a (the first ticket that will actually
need seeded organizations/affiliations to build a UI against).

## Testing performed

- New tests: `CreateOrganizationCounsellorCompensationActionTest` (6 tests, direct coverage of the
  unchanged low-level action), `OrganizationCounsellorCompensationTest` (rewritten, 21 tests —
  proposal creation, expiry defaults/overrides, validation, authorization, the
  one-pending-per-affiliation rule, and the SCRUM-123 read-path regression tests with fixture setup
  moved off the removed service method).
- Verified empirically: temporarily disabled the pending-proposal guard and confirmed the relevant
  test fails; restored and confirmed it passes again.
- Verified against the real dev MySQL database (not just the sqlite test suite): ran the new
  migration, confirmed `requests.type`'s native enum column actually accepts the new value, and
  reproduced the full propose flow end-to-end via tinker inside a rolled-back transaction.
- Full suite: 565 passed. Pint: clean.

## Files changed

- `app/Enums/RequestTypeEnum.php` — new `organizationCounsellorCompensationChange` case
- `database/migrations/2026_08_28_600000_add_expiry_columns_to_requests_table.php` (new) —
  `expires_at`/`round` columns + re-applies the `requests.type` enum column's value list
- `config/organization.php` (new) — `compensation_negotiation_default_expiry_days`,
  `compensation_negotiation_max_rounds` (the latter consumed by SCRUM-148)
- `app/Models/Request.php` — `expires_at`/`round` added to `$fillable`/`$casts`
- `app/DTOs/CreateRequestDTO.php` — widened `for` type union, new `expiresAt`/`round` fields
- `app/Actions/Request/CreateRequestAction.php` — passes through the new generic fields
- `app/DTOs/OrganizationCounsellorCompensationDTO.php` — new `expiryDays` field
- `app/Actions/Organization/EnsureOrganizationCounsellorCompensationDataIsValidAction.php` —
  validates `expiryDays` (1–30) alongside existing type-consistency checks
- `app/Actions/Organization/EnsureNoPendingOrganizationCounsellorCompensationRequestAction.php`
  (new)
- `app/Actions/Organization/ProposeOrganizationCounsellorCompensationChangeAction.php` (new)
- `app/Notifications/OrganizationCounsellorCompensationChangeProposedNotification.php` (new)
- `app/Services/OrganizationCounsellorCompensationService.php` — `setCompensation()` removed,
  `proposeCompensationChange()` added
- `app/Http/Controllers/OrganizationCounsellorCompensationController.php` — `store()` calls the
  new service method, returns the created `Request`
- `app/Http/Requests/CreateOrganizationCounsellorCompensationRequest.php` — `expiryDays` validation
- `app/Http/Resources/OrganizationRequestResource.php` — `for`→organization resolution fix,
  `proposedTerms`/`expiresAt`/`round` fields
- `app/Actions/Request/GetRequestResourceAction.php` — dispatch entry for the new type
- `tests/Unit/CreateOrganizationCounsellorCompensationActionTest.php` (new)
- `tests/Unit/OrganizationCounsellorCompensationTest.php` (rewritten)
