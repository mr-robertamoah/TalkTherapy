# SCRUM-173 (TT-6.5d): "Organizations I administer" list + admin dashboard entry point

Closes the last navigation gap found by the 2026-08-30 audit (see `documentation/decision-log.md`):
an org's own admin had no way to discover or reach their admin dashboard without knowing the raw
organization id/URL. This is the last remaining ticket in the TT-6.5/TT-6.6 Organizations epic.

## What was built

A new "Organizations I Administer" section, added as the first section on the existing
`Organization/MyOrganizations.vue` page (`/organizations/mine/dashboard`, reachable from the
account dropdown's "My Organizations" link for any authenticated user, per SCRUM-168):

- Lists every org the current user administers, with a verification-status badge and a role
  badge (Owner/Admin).
- An "open dashboard" link navigates directly to that org's admin dashboard
  (`/organizations/{organizationId}/dashboard`).
- A user who administers nothing sees a clean empty state, not a broken page.
- Reuses the existing `organizations.mine.administered` endpoint (SCRUM-160) and
  `organizations.dashboard` route (SCRUM-165) entirely unchanged.

## Not yet built

Nothing — this closes out the Organizations epic's frontend (TT-6.5a/a2/b/c/d).

## How to try it

See `documentation/seeded-data.md`'s "Organization admin dashboard (SCRUM-165)" section.
1. Log in as `org_demo_admin` / `password`, visit `/organizations/mine/dashboard` — see "Org Demo
   Wellness Collective" with "Verified"/"Owner" badges, click "open dashboard" to reach it.
2. Log in as `org_demo_plain_admin` / `password` to confirm the role badge reads "Admin".
3. Log in as any account that administers nothing (e.g. `org_demo_counsellor`) to see the empty
   state, and confirm it coexists cleanly with that account's other sections on the same page.

## Testing performed

- Full backend suite: 778 passed. Pint clean. `npm run build` succeeds.
- `reviewer`: approved pending one required fix — the "open dashboard" link nested a button
  component inside an Inertia `<Link>` (invalid `<button>`-inside-`<a>` HTML, a first in this
  codebase) — fixed by styling the `<Link>` directly, following the existing convention elsewhere
  in the app.
- `security-engineer`: no findings — confirmed the query is self-scoped to the authenticated
  user with no client-supplied identifier reaching it, and that the destination route's own
  authorization is unaffected by how the user navigated there.
- `qa-engineer` (Playwright): confirmed DONE across all three ACs, including the plain-admin role
  badge and the empty-state coexistence with a counsellor account's other sections. Zero console
  errors throughout.
- New tests: extended `tests/Feature/MyOrganizationsDashboardControllerTest.php`.

## Files changed

- `app/Http/Controllers/OrganizationController.php` — `myOrganizationsDashboard()` now includes
  an `administeredOrganizations` prop
- `resources/js/Pages/Organization/Partials/MyAdministeredOrganizationsSection.vue` (new)
- `resources/js/Pages/Organization/MyOrganizations.vue` — wires in the new section
- `tests/Feature/MyOrganizationsDashboardControllerTest.php` — extended
- `documentation/decision-log.md` — updated
