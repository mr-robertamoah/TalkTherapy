# SCRUM-166 (TT-6.5a2): Co-admin management UI

The last remaining frontend piece of the org admin dashboard (`Organization/Show.vue`, SCRUM-165),
in the same TT-6.5/TT-6.6 Organizations epic. The backend for this (owner-only add/promote/demote/
remove, last-owner protection) was already fully built and tested much earlier in the epic
(SCRUM-163) — this ticket is purely a frontend consumer of that existing, unchanged backend.

## What was built

An "Admins" section on the org admin dashboard (`/organizations/{organizationId}/dashboard`):

- Every admin listed with a role badge (Owner/Admin).
- If the current logged-in user's own role is Owner: an "add admin" button (modal: user id +
  role), and per-row "promote"/"demote" + "remove" buttons — real button components, not
  link-styled text, per existing product feedback about that pattern elsewhere in the app.
- If the current user is a plain Admin: the same list, read-only — no action controls at all.
- All actions update the table immediately from the endpoint's own refreshed response, no page
  reload. If an owner demotes/removes themselves, they immediately lose access to the action
  controls on the same page.
- Attempting to demote/remove the organization's last remaining owner surfaces the backend's own
  "An organization must always have at least one owner." error cleanly.

## Not yet built

Nothing else remains in TT-6.5a's own scope — this closes out the org admin dashboard's full
feature set (profile, counsellors, members, request queue, admins).

## How to try it

See `documentation/seeded-data.md`'s "Organization admin dashboard (SCRUM-165)" section.
1. Log in as `org_demo_admin` / `password` and visit `/organizations/1/dashboard`.
2. In the "Admins" section, promote `org_demo_plain_admin` to Owner, then demote `org_demo_admin`
   back to Admin — watch the action controls disappear immediately on your own row.
3. Log back in as `org_demo_admin` (now a plain admin) to see the read-only view, or as
   `org_demo_plain_admin` (now the sole owner) to try demoting/removing them and see the
   last-owner-protection error.

## Testing performed

- Full backend suite: 776 passed (no backend logic changed — the pre-existing
  `OrganizationAdminControllerTest`/`OrganizationAdminManagementTest` from SCRUM-163 already
  cover owner enforcement and last-owner protection). Pint clean. `npm run build` succeeds.
- `reviewer`: approved. One suggestion (a test asserting admin roles by array position rather than
  id, fragile against the unordered `admins()` relation) applied before commit.
- `security-engineer`: no blocking issues. Confirmed the client-side `isOwner` gating is purely
  cosmetic (every mutating request is still independently authorized server-side), and that
  cross-org IDOR protection is untouched. One pre-existing, low-priority finding (a mild
  user-ID-existence oracle in the add-admin endpoint, now more directly reachable via this UI)
  filed as **SCRUM-176** rather than fixed here.
- `qa-engineer` (Playwright): confirmed DONE across all four ACs, including the last-owner
  protection error and the self-demote live-regating edge case. Flagged a seed-data gap (no
  second admin seeded, requiring manual `tinker` setup to test multi-admin scenarios) — fixed
  before commit by adding `org_demo_plain_admin` to the seeder.

## Files changed

- `app/Http/Controllers/OrganizationController.php` — `dashboard()` now includes an `admins` prop
- `resources/js/Pages/Organization/Partials/AdminsSection.vue` (new)
- `resources/js/Pages/Organization/Show.vue` — wires in the new section
- `tests/Feature/OrganizationDashboardControllerTest.php` — extended
- `database/seeders/DatabaseSeeder.php` — added `org_demo_plain_admin`
- `documentation/seeded-data.md`, `documentation/decision-log.md` — updated
