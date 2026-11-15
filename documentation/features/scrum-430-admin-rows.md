# SCRUM-430 — Admin lists as rows, and a sidebar that stays in view

## What was built

- **Lists as tables.** The admin area's lists are tables. Each row has its own actions, with no "show actions"
  step. On phones the same rows stack, with short values two to a line and no sideways scrolling.
  - **Converted:** Users, Counsellors, Suspensions, Requests to resolve, Counsellor verification (requests and
    renewals) and Testimonials.
  - **Restyled to match:** pages that already had tables — Reports, Refund requests, Payouts, Organisation billing,
    Read log, the Overview money table and Emergency numbers.
  - **Still cards:** Safety alerts, Urgent help, Safeguarding exceptions, a report's page and How-tos.
- **Users.**
  - **Columns:** User, Email (a waiting new address shows for super admins), Verified, Role, Status and Two-factor
    (super admins only).
  - **Actions:** Edit, Access history (admin rows, super admins), Reset/Cancel two-factor (super admins) and Delete
    (super admins).
  - **Removed:** the old "ban" button, which did nothing.
- **Long details open in a dialog:**
  - a suspension's card, with lift and appeal;
  - a renewal's history and revoke;
  - a counsellor's admin view, with verification and statistics.
- **Sidebar (desktop).** It stays in view while the page scrolls, and stops above the footer. When it's taller than
  the window, it scrolls on its own. On phones it is the drawer, as before.

## Try it

1. Sign in as the super admin `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`).
2. Open http://localhost:8000/administrator/users. You should see one row per person, with the action buttons in the
   row.
   - **Edit** opens that person's details already filled in.
   - Press **get more** to make the page long, then scroll. The sidebar stays put until the footer arrives, and stops
     above it.
3. Make the window narrow (375px). The rows stack, and Verified / Role / Status / Two-factor sit side by side.
   **Administration menu** opens the drawer.
4. Check the other converted pages:
   - **Counsellors:** **View** opens the admin view. **Delete** shows to super admins only; cancel it.
   - **Suspensions:** **Details** / **Decide appeal** opens the suspension's card.
   - **Counsellor verification:** **Review** opens the licence review. Under **Renewals**, **History & revoke** opens
     that dialog.
   - **Testimonials:** **Show on site** / **Stop showing**.
5. Sign in as an ordinary admin. The users list has no Two-factor column and no Delete button. For someone suspended
   after a report this admin is part of (as reporter, as a guardian, and so on), the Status column is empty.

## Test data

- Use the existing seeded accounts in `documentation/seeded-data.md`. The super admin is `mr_robertamoah`.
- Already seeded:
  - pending refund and verification requests;
  - suspensions waiting for an appeal decision;
  - testimonials.

## Where it lives

- `resources/js/Components/Admin/`:
  - `AdminTable`, `AdminRow`, `AdminCell`, `AdminStatusPill`, `AdminActionButton`;
  - one row component per list.
- `resources/js/Layouts/AdminLayout.vue` (the sidebar).
- `App\Services\UserService::loadForAdminRows()` / `forAdminRow()`, with the role and suspended status in
  `App\Http\Resources\AdminUserResource`.
- Tests: `tests/Feature/AdminUserRowsTest.php`.
