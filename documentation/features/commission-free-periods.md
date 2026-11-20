# Commission-free periods (SCRUM-463: SCRUM-467 + SCRUM-468, TT-7.10a–b)

During a commission-free period the platform takes **no fee** on payments made in it, across the whole platform:
a counsellor keeps the whole of a personal payment, and an organisation pays nothing on top of what its counsellors
are owed. Part 1 (SCRUM-467, PR #370) built the fee decision and the storage; part 2 (SCRUM-468) adds the admin
screen, the labels and the on-page notice described here.

## What was built

### Admin → Payouts → "Commission-free periods"

A new card on `/administrator/payouts`, under "Platform Settings":

- **Every administrator** sees the platform-wide periods (newest start first, up to 50): start and end (shown in UTC,
  labelled GMT), a status pill, the reason, who created it and, for one ended early, who ended it.
- **Status** is worked out on the server from the period's times:
  - *upcoming*: it hasn't started;
  - *active*: it has started and not yet reached its end (periods are half-open, `[start, end)`);
  - *ended*: it reached the end it was scheduled with;
  - *cancelled*: an administrator ended it early with "End now".
- **Only a super administrator** sees the form and the buttons, and only they can change anything. An ordinary admin
  posting directly gets a **403** before anything is validated.
  - **Add period**: start and end (both UTC/GMT, as the labels say) and a reason (required, up to 500 characters,
    shown as plain text, administrators only). The start can't be in the past (a start within the last minute is
    moved up to now), the end must be after the start, both must be within 2 years, and a period may not overlap
    another platform-wide one. Touching is fine: one ending at 00:00 on the 10th and the next starting at 00:00 on
    the 10th don't overlap. The overlap check runs under a lock, so two simultaneous submissions can't both pass.
  - **End now** (active periods only, two clicks): sets the end to this moment and records who ended it. The period
    shows as *cancelled* from then on.
  - **Delete** (upcoming periods only, two clicks): removes a period that hasn't started and that no payment refers to.
  - There is no edit: a period's start never changes once set.

Per-counsellor waivers (the same table, with a counsellor) are SCRUM-464's and aren't shown or managed here.

### Labels on waived money

- **Counsellors**: on their own profile's "Payouts" section, a pending earning made during a period shows a
  **"Commission-free"** label (hover: how much was waived). The fee shows as 0.
- **Organisations**: on `/organizations/{id}/reconciliation`, a card charge whose fee was waived shows **"Waived"**
  next to its Platform Fee, and so does a retainer invoice line (expand an invoice).
- The API resources carry `waivedFeeAmount` (minor units) and a boolean (`commissionFree` on
  `CounsellorEarningResource`; `feeWaived` on `OrganizationInvoiceLineResource` and
  `OrganizationFinancedTransactionResource`). The period's id, reason and creating admin are never sent to
  non-admins.

### On-page notice (no emails)

While a platform-wide period is running, or the next one starts within **30 days**, counsellors (profile "Payouts"
section and dashboard "Earnings" card) and organisation admins (reconciliation page) see one line:

> No platform commission on payments made between 12 Dec 2026, 00:00 GMT and 19 Dec 2026, 00:00 GMT.

Only the start and end are sent to the page.

## Routes

| Method | URL | Name | Who |
|---|---|---|---|
| GET | `/administrator/payouts` | `administrator.payouts` | any admin (lists periods) |
| POST | `/administrator/settings/platform-fee-waivers` | `admin.settings.platform-fee-waivers.store` | super admin |
| POST | `/administrator/settings/platform-fee-waivers/{waiver}/end` | `admin.settings.platform-fee-waivers.end` | super admin |
| POST | `/administrator/settings/platform-fee-waivers/{waiver}/delete` | `admin.settings.platform-fee-waivers.delete` | super admin |

All three changes are throttled at 10 a minute (`throttle:10,1,<route name>`), like the other `admin.settings.*`
routes.

## Test data

From `CommissionFreePeriodSeeder` (called by `DatabaseSeeder`; see `documentation/seeded-data.md`, "Commission-free
periods"). Dates are relative to when the database was first seeded.

- Super admin `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`).
- Ordinary admin `suspension_demo_normal_admin` / `password`.
- Periods: "Demo: launch month thank-you (ended)" (60 → 30 days ago, *ended*) and "Demo: Mental Health Awareness
  week (upcoming)" (20 → 27 days ahead, *upcoming*, inside the notice horizon).
- `commission_free_demo_counsellor` / `password`: one commission-free and one ordinary pending earning.
- `org_demo_admin` / `password`: a waived USD 50.00 card charge on `/organizations/1/reconciliation`.

## How to try it

1. **Admin view (ordinary admin).** Log in as `suspension_demo_normal_admin`, open
   http://localhost:8000/administrator/payouts. "Commission-free periods" lists the two seeded periods (*upcoming*
   blue, *ended* grey) with their reasons, says only a super administrator can change them, and shows no form or
   buttons.
2. **Create (super admin).** Log in as `mr_robertamoah`, same page. Fill in a start a few days from now and an end a
   few days after that (e.g. outside the seeded upcoming period's dates), a reason, and click **add period**. The
   list now shows it as *upcoming*, created by Robert Amoah.
   - Try a start in the past → "The start cannot be in the past." Try an end before the start → "The end must be
     after the start." Try dates overlapping the seeded upcoming period → "This period overlaps another
     commission-free period."
3. **Delete.** On the new period click **delete** → **confirm delete**: it disappears. The seeded *ended* period
   offers no buttons.
4. **End now.** Create a period starting now (set the start to the current minute in UTC -- a start up to a minute ago is moved up to now) and ending tomorrow: it shows as
   *active* with an **end now** button. Click **end now** → **confirm end now**: it shows as *cancelled*, "ended by
   Robert Amoah", and its end is now. (Any payment started while it was active paid no fee.)
5. **Counsellor notice and label.** Log in as `commission_free_demo_counsellor`, open your counsellor profile
   (profile menu, or `/counsellor/{id}`) and scroll to "Payouts": the green notice line names the seeded upcoming
   period's dates in GMT, and of the two pending earnings the GHS 100.00 one (fee GHS 0.00) has a
   **Commission-free** label. `/counsellor/dashboard`'s Earnings card shows the same notice.
6. **Organisation notice and label.** Log in as `org_demo_admin`, open
   http://localhost:8000/organizations/1/reconciliation: the notice is under the heading, and in "Financed Sessions"
   the USD 50.00 row dated about seven weeks ago shows Platform Fee USD 0.00 with **Waived**.
7. **Direct-post refusal (optional).** As `suspension_demo_normal_admin`, a POST to any of the three routes above
   returns 403.

A successful result: every admin sees the list and statuses; only the super admin can add, end or delete; the notice
appears only while a period is running or starts within 30 days, showing only its dates; waived money is labelled.

## Notes

- To see the notice disappear, delete the seeded upcoming period (and any you created) as the super admin.
- Design decisions (cancelled vs ended, the 30-day horizon, validation limits, the delete guard) are in
  `documentation/decision-log.md`, "SCRUM-468 — commission-free periods: admin, labels, notices".
