# SCRUM-160 (TT-6.6b): "My organizations" list endpoints (counsellor + member)

Last of the M4a backend-enablement sub-tickets (see `documentation/decision-log.md`'s 2026-08-29
TT-6.5/TT-6.6 restructuring entry). Backend-only — no frontend UI yet; TT-6.5b/c (later
sub-tickets) build the counsellor/member "my organizations" UI that consumes this.

## What was built

Three new self-scoped, authenticated-only endpoints:

- `GET /organizations/mine/counsellor-affiliations` — the authenticated user's own
  `Counsellor::organizationCounsellors()` (any status: pending/active/ended), each with the
  organization and current compensation terms. A user with no `Counsellor` account gets a clean
  422, not an empty list.
- `GET /organizations/mine/memberships` — the authenticated user's own
  `User::organizationMemberships()` (any status), each with the organization and current billing
  config.
- `GET /organizations/mine/administered` — the authenticated user's own
  `User::administeredOrganizations()`, each with their `role` (owner/admin) on that org.

All three are simple, self-scoped relation reads with no new authorization guard needed — the
query is already scoped to the requesting user's own data, and both `latestCompensation`/
`latestBillingConfig` (the eager-loadable relations SCRUM-159 introduced) are reused unchanged to
avoid N+1.

## Not yet built

The frontend consuming this (TT-6.5b/c, separate sub-tickets).

## How to try it

No seeded data yet. Via `php artisan tinker` or a REST client against a logged-in session:
- `GET /organizations/mine/memberships` / `/mine/counsellor-affiliations` / `/mine/administered`
  after creating the relevant affiliation/membership/admin-attach records for that user.

## Testing performed

- Full backend suite: 749 passed (up from 748 before a reviewer-suggested test addition, 736 at
  develop HEAD after SCRUM-159/161/162/163/164 merged). Whole-file Pint clean on every touched
  file.
- `reviewer` subagent: approved, no required changes. One accuracy correction applied — the
  `administeredOrganizations()` ordering's ambiguous-column rationale was verified (empirically,
  against real MySQL) to not actually reproduce with this relation's current pivot-aliasing
  behavior; the qualification was kept (harmless, still correct practice) but the comment and test
  description were corrected to stop overstating it as an established failure mode. Also added an
  N+1 regression test for consistency with SCRUM-159's equivalent.
- `security-engineer` subagent: no issues found — all three endpoints are query-scoped entirely
  off `$request->user()`, with no route parameter or request field able to redirect any query at
  another user's data.
- New tests: `tests/Unit/MyOrganizationsTest.php` (every-status inclusion for both affiliations
  and memberships, the counsellor-account-missing 422, empty-list-not-error for memberships/
  administered, administered-organizations ordering), `tests/Feature/MyOrganizationsControllerTest.php`
  (real-route listing for all three, guest-401, the `/mine/...` vs. `/{organizationId}` route-
  collision regression, an N+1 regression guard for the counsellor-affiliations list).

## Files changed

- `app/Actions/Organization/GetMyOrganizationCounsellorAffiliationsAction.php`,
  `GetMyOrganizationMembershipsAction.php`, `GetMyAdministeredOrganizationsAction.php` (all new)
- `app/Http/Resources/MyOrganizationCounsellorAffiliationResource.php`,
  `MyOrganizationMembershipResource.php`, `MyAdministeredOrganizationResource.php` (all new)
- `app/Services/OrganizationService.php` — three new methods
- `app/Http/Controllers/OrganizationController.php` — three new methods
- `routes/web.php` — three new routes
