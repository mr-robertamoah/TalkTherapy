# SCRUM-167 (TT-6.5b): Counsellor "my organizations" + apply flow

Counsellor-facing counterpart to SCRUM-165's org admin dashboard, in the same TT-6.5/TT-6.6
Organizations epic (see `documentation/decision-log.md`'s 2026-08-30 entries). Filed after a
navigation-gap audit found that SCRUM-160's backend "my organizations" endpoints had zero frontend
consumers — a counsellor had no way to see their own org affiliations or reach an org's admin
dashboard.

## What was built

A new `resources/js/Pages/Organization/MyOrganizations.vue` page, reachable at
`GET /organizations/mine/dashboard` and linked from the account dropdown nav ("My Organizations",
shown only to users with a counsellor account):

- **My Organizations**: every org the counsellor is affiliated with (any status), with current
  compensation. An affiliation still `PENDING` (compensation not agreed) is worded distinctly
  from a pending Request in the queue below.
- **Pending Applications & Invites**: invites/applications addressed to or from the counsellor,
  plus any compensation-change negotiation currently awaiting their decision — with the proposed
  terms, negotiation round, and expiry all shown before deciding. Accept/reject reuse the existing
  generic `requests.respond` endpoint; a compensation negotiation additionally supports
  counter-offering via a shared `CompensationCounterOfferModal.vue` component (also now used by
  the org-admin side, extracted from what was previously duplicated inline there).
- **Browse Organizations**: verified provider orgs with an "apply" action
  (`organizations.counsellors.apply`, unchanged).

Accepting a compensation change refreshes the affiliations table's compensation cell immediately,
without a manual reload.

## Not yet built

The member-facing equivalent (SCRUM-168, separate ticket) and the "organizations I administer"
list (SCRUM-173, separate ticket, filed alongside this one from the same navigation audit).

## How to try it

See `documentation/seeded-data.md`'s "Organization admin dashboard (SCRUM-165)" section. Quick
start:
1. Log in as `org_demo_counsellor` / `password` (login form defaults to "Login with username").
2. Open the account dropdown → "My Organizations", or visit `/organizations/mine/dashboard`
   directly.
3. See the active affiliation (USD 2000) and a pending compensation-change negotiation (org
   proposing USD 2500) — accept, reject, or counter-offer it.
4. Log in as `org_demo_applicant` / `password` to see the "request pending, no affiliation yet"
   state, and to try applying to the same org again (a clean duplicate-application error).

## Testing performed

- Full backend suite: 773 passed. Pint clean on all touched files. `npm run build` succeeds.
- `reviewer`: approved pending this doc + the decision-log entry (both now written); no code
  changes required. Two non-blocking suggestions noted for follow-up, not fixed here: filtering
  already-affiliated/applied orgs out of Browse Organizations, and a pre-existing N+1 query
  pattern in both the org-admin and counsellor request-queue actions (not introduced by this
  ticket).
- `security-engineer`: no blocking issues — scoping, PII exposure, and authorization for the new
  self-scoped endpoints are sound. Caught one real issue: I had accidentally overwritten an
  existing SCRUM-160 test file instead of extending it, deleting its coverage — restored and
  moved my new tests to a distinctly-named file instead.
- `qa-engineer` (Playwright): found the accept path broken for the seeded compensation negotiation
  (missing `proposedById` in the seed data) — fixed. Flagged a second, intermittent, unreproducible-
  by-static-review click issue on invite accept/reject as needing a definitive answer rather than a
  shrug; investigated directly and confirmed it was an artifact of incorrectly-constructed ad-hoc
  test data (a missing `for` polymorphic association), not a real defect — reproduced the failure,
  fixed the test data, and confirmed the real flow works reliably on the first click every time.
- New tests: `tests/Unit/MyOrganizationRequestQueueTest.php`,
  `tests/Feature/MyOrganizationsDashboardControllerTest.php`.

## Files changed

- `app/Actions/Organization/GetMyOrganizationRequestQueueAction.php` (new)
- `app/Services/OrganizationService.php` — `getMyOrganizationRequestQueue()`
- `app/Http/Controllers/OrganizationController.php` — `myOrganizationsDashboard()`,
  `myOrganizationRequestQueue()`
- `routes/web.php` — two new routes (and a reorder of the existing `mine/*` block, see decision log)
- `resources/js/Pages/Organization/MyOrganizations.vue`,
  `Partials/{MyAffiliationsSection,MyOrganizationRequestQueueSection,BrowseProviderOrganizationsSection}.vue`
  (all new)
- `resources/js/Components/CompensationCounterOfferModal.vue` (new, extracted)
- `resources/js/Pages/Organization/Partials/RequestQueueSection.vue` — refactored to use the
  extracted modal
- `resources/js/Components/RequestBadge.vue` — added message text for 5 previously-blank
  org-context request types
- `resources/js/Layouts/AuthenticatedLayout.vue` — "My Organizations" nav link
- `database/seeders/DatabaseSeeder.php` — added a pending compensation-change negotiation to the
  existing `org_demo_counsellor` seed data
- `.gitignore` — ignore Playwright MCP scratch output
- `documentation/seeded-data.md`, `documentation/decision-log.md` — updated
