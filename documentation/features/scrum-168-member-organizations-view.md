# SCRUM-168 (TT-6.5c): Member "your organizations" view

Member-facing, read-only counterpart to SCRUM-167's counsellor "my organizations" dashboard, in
the same TT-6.5/TT-6.6 Organizations epic (see `documentation/decision-log.md`'s 2026-08-30
entries).

## What was built

Rather than a separate page, the existing `Organization/MyOrganizations.vue` page
(`GET /organizations/mine/dashboard`) is now reachable by **any** authenticated user, not just
counsellors:

- **My Memberships** (new): every org the user is a member of, with status and current billing
  config. Always shown, for any user.
- The counsellor-only sections (My Organizations affiliations, Pending Applications & Invites
  queue, Browse Organizations) are shown only if the user has a Counsellor account — a plain
  member simply doesn't see them, rather than the page erroring or redirecting.
- A member accepts/rejects an org's invite or application through the pre-existing, app-wide
  personal "Requests" nav-dropdown modal — no new dedicated queue was needed for this ticket,
  unlike SCRUM-167's counsellor version (which needed extra negotiation detail a member's flat
  billing config doesn't have).
- **Bug fix, found via manual QA, not part of this ticket's original scope**: that same generic
  "Requests" modal (`RequestBadge.vue`) previously rendered a literal "from: @undefined" for any
  request whose party is an organization — fixed, and this also happens to fix the same issue for
  a deleted counsellor/user party.

## Not yet built

Member self-apply to a consumer org (TT-6.5c2, separate ticket, deliberately narrowed out of this
one's scope per its own Jira description).

## How to try it

See `documentation/seeded-data.md`'s "Organization admin dashboard (SCRUM-165)" section. Quick
start:
1. Log in as `org_demo_member` / `password` and visit `/organizations/mine/dashboard` (or the
   account dropdown → "My Organizations") — see the active membership with its retainer billing
   config.
2. Log in as `org_demo_member_invitee` / `password`, open the account dropdown → "Requests", find
   the pending org invite, accept it — then revisit the dashboard to see the new membership.
3. Log in as `org_demo_counsellor` / `password` to confirm a counsellor sees both their counsellor
   sections and the My Memberships section together on the same page.

## Testing performed

- Full backend suite: 775 passed. Pint clean on all touched files. `npm run build` succeeds.
- `reviewer`: approved pending one required change (seeded-data.md doc update for the new
  account) and one suggested simplification (a dead `PENDING` status branch copied from an
  already-shipped sibling component) — both applied before commit, since this was a brand-new,
  not-yet-merged file.
- `security-engineer`: no blocking issues — traced the relaxed dashboard guard, the memberships
  resource's scoping, and the `RequestBadge.vue` fix's data provenance; confirmed no cross-user
  leakage in any direction and no new exposure introduced by the fix.
- `qa-engineer` (Playwright): confirmed DONE across all four ACs (member sees memberships;
  accept/reject via the generic modal; a non-counsellor loads the page cleanly; a counsellor who's
  also a member sees both section sets). Found one further pre-existing, out-of-scope bug (a blank
  message for group-therapy membership requests in the same generic modal) — filed as **SCRUM-175**
  rather than fixed here.
- Tests extended: `tests/Feature/MyOrganizationsDashboardControllerTest.php` (new cases for
  non-counsellor access, memberships appearing for both counsellor and non-counsellor users, and a
  regression test pinning that a member can accept an invite via the pre-existing generic
  `requests.respond` endpoint).

## Files changed

- `app/Http/Controllers/OrganizationController.php` — `myOrganizationsDashboard()` relaxed to any
  authenticated user, now also fetches memberships
- `resources/js/Pages/Organization/MyOrganizations.vue` — conditional sections, new memberships
  prop
- `resources/js/Pages/Organization/Partials/MyMembershipsSection.vue` (new)
- `resources/js/Components/RequestBadge.vue` — `partyLabel()` fix for organization (and deleted)
  parties
- `resources/js/Layouts/AuthenticatedLayout.vue` — nav link visibility widened to any user
- `database/seeders/DatabaseSeeder.php` — added `org_demo_member_invitee` (pending member invite)
- `tests/Feature/MyOrganizationsDashboardControllerTest.php` — extended
- `documentation/seeded-data.md`, `documentation/decision-log.md` — updated
