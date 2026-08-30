# SCRUM-165 (TT-6.5a): Organization admin dashboard

First frontend ticket in the TT-6.5/TT-6.6 Organizations epic (see `documentation/decision-log.md`'s
2026-08-29 TT-6.5/TT-6.6 restructuring entry and 2026-08-30 SCRUM-165 entry). Consumes the
already-merged backend work from SCRUM-159 (org-scoped lists), SCRUM-161 (directory, not used
directly here), SCRUM-162 (org-scoped request queue pattern), and SCRUM-163 (owner enforcement,
not yet surfaced in this UI — that's TT-6.5a2, a separate ticket).

## What was built

A new `resources/js/Pages/Organization/Show.vue` page, reachable at
`GET /organizations/{organizationId}/dashboard`, admin-gated (same
`EnsureUserIsOrganizationAdminAction` guard as the existing JSON API):

- **Organization profile**: view + edit (via modal, `organizations.update`), verification/
  provider/consumer status badges.
- **Affiliated Counsellors** (shown if `is_provider`): table with status and current compensation,
  an "invite counsellor by id" action, paginated with "load more".
- **Members** (shown if `is_consumer`): table with status and current billing config, an
  "invite member by id" action, a per-member billing-config editor, paginated with "load more".
- **Pending Applications & Invites**: the org's own request queue (new
  `GetOrganizationRequestQueueAction`, mirroring SCRUM-162's `orWhere` shape but scoped to one
  org), with accept/reject (reusing the existing generic `requests.respond` endpoint) and, for a
  compensation negotiation currently awaiting the org's decision, a counter-offer action.
- Both sections' `PENDING`-status rows are worded distinctly from the request queue's own pending
  items ("Affiliated -- awaiting compensation/billing agreement" vs. "Pending Applications &
  Invites") per AC7's requirement to distinguish an existing-but-unsettled affiliation from a
  Request with no affiliation yet.

## Not yet built

Co-admin management UI (TT-6.5a2, separate ticket, deliberately out of scope here).

## How to try it

See `documentation/seeded-data.md`'s "Organization admin dashboard (SCRUM-165)" section for the
full account roster. Quick start:
1. Log in at `/login` with username `org_demo_admin`, password `password` (the login form
   defaults to "Login with username", not email).
2. Visit `/organizations/1/dashboard` (id 1 in a fresh seed) — "Org Demo Wellness Collective".
3. All four sections render with real seeded data: one active counsellor with agreed compensation,
   one counsellor with a pending application, one active member with a retainer billing config,
   one user with a pending member application.

## Testing performed

- Full backend suite: 748 passed. Whole-file Pint clean on every touched backend file.
  `npm run build` succeeds.
- `reviewer` subagent: approved, two minor non-blocking suggestions applied (a nonexistent-org
  404 test case, an error-message key mismatch in the reject/accept handler).
- `security-engineer` subagent: no blocking issues — authorization scoping, PII minimization, and
  the client-side-only nature of the request-queue's actionability check were all independently
  verified sound against the server-side guards. One suggested regression test (admin-of-a-
  different-org) added.
- `qa-engineer` subagent: **first pass found the feature not done** — "load more" pagination was
  completely broken on all three lists, compensation counter-offers always failed silently (and
  every toast in the three new section components was invisible due to a missing `<Alert>`
  render), and the profile edit modal showed stale data after saving. All fixed; a second
  QA pass then caught one follow-on bug in the fix itself (validation errors rendering as raw
  arrays), also fixed. A third, independent re-verification pass confirmed all of the above
  resolved, with broadened coverage across all three sections and both PERCENTAGE/FREE
  compensation types. Full reasoning and the fixes' rationale in `documentation/decision-log.md`.
- One pre-existing, cross-cutting gap surfaced during QA (the generic request-respond flow never
  re-checks a `Request` is still `PENDING`) was deliberately deferred as **SCRUM-171**, not fixed
  here — see the decision log for why.
- New tests: `tests/Unit/OrganizationRequestQueueTest.php`, `tests/Feature/OrganizationDashboardControllerTest.php`.

## Files changed

- `app/Actions/Organization/GetOrganizationRequestQueueAction.php` (new)
- `app/Services/OrganizationService.php` — `getOrganizationRequestQueue()`
- `app/Http/Controllers/OrganizationController.php` — `dashboard()`, `requestQueue()`,
  `paginatedResource()` helper
- `routes/web.php` — two new routes
- `resources/js/Pages/Organization/Show.vue`,
  `Partials/{UpdateOrganizationForm,CounsellorsSection,MembersSection,RequestQueueSection}.vue`
  (all new)
- `resources/js/Composables/useEnums.js` — 5 new org-directed `RequestTypeEnum` entries
- `database/seeders/DatabaseSeeder.php` — `createOrganizationDashboardDemoData()`
- `documentation/seeded-data.md` — new accounts documented
