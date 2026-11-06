# Admin area: sidebar, requests to resolve, overview (SCRUM-387, SCRUM-49)

## What was built

The old single `/administrator` page with its tab bar is gone. It is replaced by one admin area
with a grouped left sidebar. Every section has its own URL, and the existing admin URLs and route
names are unchanged.

### The shell

- `resources/js/Layouts/AdminLayout.vue` wraps `AuthenticatedLayout`, so the alerts, the live
  suspension redirect and the guardian listeners keep working. Every admin page uses it as an
  Inertia persistent layout (`defineOptions({ layout: AdminLayout })`), so the shell stays mounted
  while you move between admin pages.
- The sidebar is defined once, on the server, in `App\Support\AdminNavigation::for($user)`. It is
  already filtered by role and is shared from `HandleInertiaRequestsV2` on admin routes only. The
  groups, in order:
  - Overview
  - People: Users, Counsellors, Counsellor verification
  - Requests to resolve
  - Safety: Reports, Suspensions, Safeguarding exceptions, Read log (super admins only)
  - Money: Payouts, Refund requests, Organisation billing
  - Content: Testimonials, How-tos
- The current page is marked `aria-current="page"`. A report's own page marks Reports.
- Badges say "N waiting" in text. They come from their own JSON route, `GET /administrator/badges`
  (`admin.badges`, `GetAdminToDoCountsAction::badges()`), never with a page: the layout fetches
  them (`useAdminBadges.js`) on mount, after each navigation and after any successful visit,
  throttled to one a second, and a page that resolves something over axios refreshes them at once.
  The client is Inertia v1, which has no deferred props (see `.claude/memory.md`). The Refund
  requests badge is pending refund requests plus failed refunds.
- Desktop (lg and up) shows a static sidebar, `<nav aria-label="Administration">`. Below lg, an
  "Administration menu" button opens a drawer (`role="dialog"`, `aria-modal`). The drawer:
  - traps focus, and closes on Esc, on the backdrop, on navigating, or when the window grows to lg;
  - locks body scroll while open, and makes the page behind it `inert`;
  - returns focus to the toggle (`aria-expanded`, `aria-controls`) when it closes.
- Touch targets are at least 44px, and there is a "Skip to main content" link.
- Access: a new `admin` route middleware (`App\Http\Middleware\EnsureUserIsAdmin`) is on every
  `/administrator*` page and write route and on the `/api/administrator/*` JSON routes. It sends a
  non-admin home with "You are not authorized to visit this page.", or answers JSON with a 403.
  The older controllers' own checks stay as a backstop, and the read log and every super-admin
  action still check for a super admin on the server.

### New pages (each hosts the component the old tab used)

| URL | Page | Notes |
|---|---|---|
| `/administrator/users` | People › Users | `AdminUsersComponent` with its own "get more" paging |
| `/administrator/counsellors` | People › Counsellors | Counsellor list with a name search, kept in `?search=` |
| `/administrator/counsellor-verification` | People › Counsellor verification | Pending by default; `?status=ACCEPTED`, `REJECTED`, `all`… The API gained a `filterType=status` filter |
| `/administrator/requests` | Requests to resolve | See below |
| `/administrator/testimonials` | Content › Testimonials | |
| `/administrator/how-tos` | Content › How-tos | `AdminHowToComponent` |

The old tabs for therapies, group therapies, statistics and contacts were empty, so they are not
carried over.

### Requests to resolve (`/administrator/requests`)

- **One queue** for everything waiting on an admin, from a fixed list of types:
  - counsellor verification;
  - organisation verification (any admin can now see and answer it);
  - age verification;
  - date-of-birth change with no guardian;
  - refund;
  - safeguarding exception (super admins only).
- **Shared scope:** the queue, the counts and the personal Requests list all use
  `Request::scopeAwaitingAdmin($viewer, $types)`. The personal list's behaviour is unchanged.
- **Filters:**
  - `?type=` is one of the queue types you may see. Any other (unknown, or safeguarding for an
    ordinary admin) is ignored, so the list and the filter shown always agree.
  - `?status=` is `PENDING` by default, any request status, or `all`.
  - The list is server-paged.
- **Rows:**
  - Each row is the usual `RequestBadge`, rendered through `RequestResource`, so its masking still
    applies.
  - Accept and reject go through `requests.respond`. Each row says whether you may answer it
    (`isRespondent`). Rejecting a refund asks for a reason first, as on the refund page, and a
    refusal shows the server's message.
  - Answered rows show when, and which administrator, answered (`requests.responded_by_id`, name
    and username only, never on a row you are involved in).
  - Counsellor verifications and refunds also link to their own pages.
  - No row carries the super admin's or a payer's dob, gender or country: a verification's and a
    refund's `to` is not sent, and a refund's payer is name and username only.
- **Who may answer:** an administrator answers only these types (`App\Support\AdminRequestQueue`,
  `EnsureUserCanRespondToRequestAction`). Any other request -- a guardianship, a group membership, an
  organisation flow, a schedule proposal, a dob change with a guardian -- needs its real party.
  - Your own age verification shows no buttons, with "another administrator needs to review it".
  - A super admin who counsels the minor in a safeguarding request sees only that it exists.

### Overview (`/administrator`)

- **"Waiting on an administrator"** (`GetAdminToDoCountsAction`). Each card links to where the
  item is resolved, pre-filtered where that page has a filter. Zero reads "nothing waiting".
  - All admins see:
    - counsellor verifications;
    - organisation verifications;
    - age verifications that aren't their own;
    - dob changes with no guardian;
    - refund requests;
    - reports open or under review;
    - suspension appeals awaiting a decision;
    - paused minors with an open therapy and no exception;
    - failed payouts not yet paid again;
    - failed refunds;
    - organisations that are billing-suspended or have a failed invoice.
  - Super admins also see:
    - safeguarding requests they can answer;
    - safeguarding requests they're involved in;
    - safeguarding requests no longer needed;
    - failed background jobs (SCRUM-49's "system health").
- **Caching:**
  - Shared counts are cached for 60s, worked out with no viewer; super admins' own counts have their
    own key.
  - Person-scoped counts are worked out per viewer or cached per user.
  - The paused-minors count is cached for 5 minutes, only appears on the overview, and isn't
    cleared by answers.
  - Creating an admin queue request, or changing its status, clears the rest (best effort, after
    commit). See "Admin overview caching" in `documentation/operations/monitoring.md`.
- **Pages behind the counts:** the refund requests page also lists failed refunds (amount, why it
  failed, when, the payer's name), and organisation billing also lists organisations with a failed
  invoice that aren't suspended (retry settlement; lift only for a suspended one).
- **Platform** (`GetPlatformStatsAction`) has three views: this week, this month and all time.
  The week and month views show the change against last week or last month.
  - **Right now:** users, counsellors (verified and awaiting), organisations (verified), therapies
    and group therapies in session (pending shown too), upcoming sessions and suspensions in
    force.
  - **Per period:** new users, sessions held, sessions failed or abandoned and their share,
    approximate video minutes, and reports opened.
  - **Money:** revenue, platform fees, payouts paid, refunds paid and organisation invoices
    settled. Money is always per currency, and only successful, non-reversed rows count.
  - The figures are cached for 15 minutes and show an "As of" time.
  - Everything is a total: no names or lists.

## How to try it

1. `docker compose up -d`. Then open http://localhost:8000/login and log in as the super admin
   (`mr_robertamoah`).
2. Open the account menu and choose **Administrator** (http://localhost:8000/administrator).
   - Expected: the sidebar, with "N waiting" badges after a moment.
   - The overview cards show 1 counsellor verification, 1 organisation verification, 1 age
     verification, 1 dob change, 1 refund request, 3 reports, 1 suspension appeal, 1 paused
     minor, 1 failed payout, 1 failed refund, 1 organisation, 1 safeguarding request you can
     answer and 1 failed job. Zero counts read "nothing waiting".
   - The Platform panel shows money in GHS and USD columns, never added together.
3. Click **Organisation verifications**.
   - Expected: the queue filtered to that type, with the URL
     `/administrator/requests?type=ORGANIZATION_VERIFICATION_REQUEST`.
   - Accept it. The row leaves the pending list, the badge drops by one, and the organisation
     becomes verified.
4. Log out, then log in as `suspension_demo_normal_admin` (password `password`).
   - The sidebar has no Read log.
   - The queue lists no safeguarding requests. `?type=SAFEGUARDING_EXCEPTION_REQUEST` lists
     nothing.
   - The organisation and counsellor verifications have accept and reject buttons.
5. Narrow the window below 1024px.
   - The sidebar becomes an "Administration menu" button.
   - Open it, Tab through it (focus stays inside), then press Esc. The menu closes and focus
     returns to the button.
   - Tapping the backdrop or a link closes it too.
6. As any signed-in non-admin, visit http://localhost:8000/administrator/requests.
   - Expected: you're sent home with "You are not authorized to visit this page."

## Test data

- Another admin to suspend: `suspendable_demo_admin` / `password`, named in a resolved report
  ready for a suspension (log in as `mr_robertamoah`, open Reports).
- Involved masking: `involved_demo_super_admin` / `password` counsels `paused_minor` in "Paused
  Minor Demo Group". Their queue shows `paused_minor`'s safeguarding request only as one they are
  involved in.
- Content: two seeded testimonials and the how-to "Answering a request".

- Super admin: `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`).
- Normal admin: `suspension_demo_normal_admin` / `password`.
- Queue and count fixtures: see "Admin area: requests to resolve and the overview (SCRUM-387,
  SCRUM-49)" in `documentation/seeded-data.md`.
  - `admin_area_verify_counsellor` has the counsellor verification.
  - `admin_area_org_owner`'s Verify Demo Organisation has the organisation verification.
  - `admin_area_payout_counsellor` has the failed payout and the failed refund.
  - The other types come from the existing demo data (`age_verification_demo_user`,
    `dobchange_demo_no_guardian`, the refund demo, `paused_minor`'s safeguarding request, the
    report and suspension demos).

## Tests

- `tests/Feature/AdminAreaTest.php`: route access, the middleware on every admin GET, the
  sidebar per role, and that the shared props reach admin pages only.
- `tests/Feature/AdminRequestQueueTest.php`: a `getRequests` regression for each admin branch;
  the queue's types, filters and masking; an admin's own age verification; and a query count that
  doesn't grow with the rows.
- `tests/Feature/AdminRespondPermissionsTest.php`: an admin refused every party-only type, allowed
  every queue type, who answered recorded and shown, and no super admin or payer PII in the queue.
- `tests/Feature/AdminAreaTest.php` also covers the badge route (admins only, no page queries) and
  the middleware on every admin write and JSON route.
- `tests/Feature/AdminToDoCountsTest.php`: the counts match their lists, each count's
  permissions, the caching and its clearing on respond, and the seed.
- `tests/Feature/AdminPlatformStatsTest.php`: per-currency money, the status filters, the periods,
  and the caching.
