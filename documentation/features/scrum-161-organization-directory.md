# SCRUM-161 (TT-6.6c): Organization directory/browse endpoint

First of the M4a (Organizations backend enablement) sub-tickets restructuring SCRUM-111's TT-6.5
(Organizations frontend). See `documentation/decision-log.md`'s 2026-08-29 "TT-6.5 (Organizations
frontend)" entry for the full history. Highest-leverage ticket in the M4a batch — blocks TT-6.5b
(counsellor apply) and TT-6.5c2 (member self-apply).

## What was built

A new `GET /organizations` endpoint — the first way for **any authenticated user** (not just an
org's own admins) to discover organizations to apply to. Every other existing Organization
endpoint is admin-gated; this one deliberately opens a new, narrower trust boundary.

- **Verified-only** (2026-08-29 decision): only organizations with `verified_at` set appear. An
  unverified organization stays invisible until a platform admin verifies it.
- **Curated field exposure**: `OrganizationDirectoryResource` returns only `id`, `name`,
  `description`, `logoUrl`, `isProvider`, `isConsumer`, `selfApplyEnabled` — deliberately excludes
  `legalName`/`registrationNumber`/`email`/`phone` (admin-only PII exposed by the existing
  admin-facing `OrganizationResource`) and anything about an org's current members/counsellors.
- **Optional filters**: `?isProvider=1`/`?isConsumer=1` so a counsellor sees provider orgs and a
  member sees consumer orgs, not an undifferentiated list. Filters combine as AND.
- Paginated (`PaginationEnum::preferencesPagination`, 10/page).
- Throttled (`throttle:60,1`) — added after security review flagged this as the first endpoint
  letting any authenticated user enumerate the entire verified-org roster; every other Organization
  endpoint reachable at meaningful frequency already carries a throttle.

## How to try it

Log in as any user and hit `GET /organizations` (optionally `?isProvider=1` or `?isConsumer=1`).
No seeded verified organization exists yet in this dev environment's demo data — verify an
organization first via the platform admin flow (or directly in tinker:
`Organization::first()->verify()`) to see it appear.

## Not yet built

- TT-6.6a (org-scoped member/counsellor list endpoints), TT-6.6b ("my organizations"), TT-6.6d
  (org-scoped request queue), TT-6.6e (co-admin management) — the rest of M4a.
- TT-6.7 (shareable self-apply link) — additive to this directory, not a replacement.
- Any frontend consuming this endpoint (M4b, TT-6.5a/b/c/a2/c2).

## Testing performed

- Full backend suite: 681 passed (up from 672 before this branch). Whole-file Pint clean.
- New tests: `tests/Unit/OrganizationServiceTest.php` (verified-only, isProvider filter, isConsumer
  filter, AND-combination of both filters) and `tests/Feature/OrganizationDirectoryControllerTest.php`
  (real HTTP route: authenticated browse, unverified-exclusion, curated-field black-box check via
  string-search on the raw JSON response, isProvider filter, guest 401).
- `reviewer` and `security-engineer` subagent review completed. Reviewer found and fixed one N+1
  (the `logo` relation wasn't eager-loaded — confirmed via a temporary query-count test: 10 queries
  vs. 6 with `with('logo')`). Security-engineer found and fixed one gap (no throttle on a
  newly-enumerable full-org-directory endpoint).

## Files changed

- `app/DTOs/GetOrganizationDirectoryDTO.php` (new)
- `app/Actions/Organization/GetOrganizationDirectoryAction.php` (new)
- `app/Http/Resources/OrganizationDirectoryResource.php` (new)
- `app/Services/OrganizationService.php` — `getOrganizationDirectory()`
- `app/Http/Controllers/OrganizationController.php` — `index()`
- `routes/web.php` — `organizations.index`
- `tests/Unit/OrganizationServiceTest.php`, `tests/Feature/OrganizationDirectoryControllerTest.php` (new)
