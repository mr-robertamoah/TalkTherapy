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

Per-counsellor periods (the same table, with a counsellor) aren't shown or managed here -- see "One counsellor's own
periods" below (SCRUM-475).

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
| GET | `/administrator/settings/platform-fee-waivers/events` | `admin.settings.platform-fee-waivers.events` | super admin (JSON history) |

All three changes are throttled at 10 a minute (`throttle:10,1,<route name>`), like the other `admin.settings.*`
routes.

## Test data

From `CommissionFreePeriodSeeder` (called by `DatabaseSeeder`; see `documentation/seeded-data.md`, "Commission-free
periods"). To add it to an existing local database without touching anything else, run:

```bash
docker compose exec -u www-data php php artisan db:seed --class=CommissionFreePeriodSeeder
```

It skips whatever already exists, so it is safe to run again. The periods' dates are relative to the run that first
created them.

- The seeded super admin, `mr_robertamoah` (password from `SUPER_PASSWORD` in `.env.docker`).
- An ordinary admin, `suspension_demo_normal_admin` (the shared demo password -- see `documentation/seeded-data.md`).
- Periods: "Demo: launch month thank-you (ended)" (60 → 30 days ago, *ended*) and "Demo: Mental Health Awareness
  week (upcoming)" (20 → 27 days ahead, *upcoming*, inside the notice horizon).
- `commission_free_demo_counsellor` (shared demo password): three pending GHS earnings -- two commission-free, one
  ordinary.
- `org_demo_admin` (shared demo password): a waived GHS 50.00 card charge on `/organizations/1/reconciliation`, for
  "Commission-Free Demo Org Therapy" (`org_demo_payperuse_member` with `commission_free_demo_counsellor`). No other
  demo account's charges or balances are touched.

## How to try it

1. **Admin view (ordinary admin).** Log in as `suspension_demo_normal_admin`, open
   http://localhost:8000/administrator/payouts. "Commission-free periods" lists the two seeded periods (*upcoming*
   blue, *ended* grey) with their reasons, says only a super administrator can change them, and shows no form or
   buttons.
2. **Create (super admin).** Log in as `mr_robertamoah`, same page. Fill in a start a few days from now and an end a
   few days after that (e.g. outside the seeded upcoming period's dates), a reason, and click **add period**. The
   list now shows it as *upcoming*, created by Robert Amoah.
   - Try a start in the past → "The start cannot be in the past." Try an end before the start → "The end must be
     after the start, and in the future." Try dates overlapping the seeded upcoming period → "This period overlaps another
     commission-free period."
3. **Delete.** On the new period click **delete** → **confirm delete**: it disappears. The seeded *ended* period
   offers no buttons. (A period starting within the next minute can no longer be deleted -- end it once it runs.)
4. **End now.** Create a period starting now (set the start to the current minute in UTC -- a start up to a minute
   ago is moved up to now) and ending tomorrow: it shows as *active* with an **end now** button. Click **end now** → **confirm end now**: it shows as *cancelled*, "ended by
   Robert Amoah", and its end is now. (Any payment started while it was active paid no fee.)
5. **Counsellor notice and label.** Log in as `commission_free_demo_counsellor`, open your counsellor profile
   (profile menu, or `/counsellor/{id}`) and scroll to "Payouts": the green notice line names the seeded upcoming
   period's dates in GMT; the GHS 100.00 earning with fee GHS 0.00 carries **Commission-free · GHS 10.00 waived**, the
   GHS 50.00 one with fee GHS 0.00 **Commission-free · GHS 5.00 waived** (at the default 10% fee), and the ordinary
   GHS 50.00 one, fee GHS 5.00, has none. `/counsellor/dashboard`'s Earnings card shows the same notice.
6. **Organisation notice and label.** Log in as `org_demo_admin`, open
   http://localhost:8000/organizations/1/reconciliation: the notice is under the heading, and in "Financed Sessions"
   the "Therapy with Dr. CommissionFree DemoCounsellor" row (GHS 50.00, dated about seven weeks ago) shows Platform
   Fee GHS 0.00 with **Waived · GHS 5.00 waived**.
7. **Direct-post refusal (optional).** As `suspension_demo_normal_admin`, a POST to any of the three routes above
   returns 403.

A successful result: every admin sees the list and statuses; only the super admin can add, end or delete; the notice
appears only while a period is running or starts within 30 days, showing only its dates; waived money is labelled.

## Notes

- To see the notice disappear, delete the seeded upcoming period (and any you created) as the super admin. Running
  the seeder command above afterwards puts the upcoming period back, 20 → 27 days from then.
- Design decisions (cancelled vs ended, the 30-day horizon, validation limits, the delete guard) are in
  `documentation/decision-log.md`, "SCRUM-468 — commission-free periods: admin, labels, notices".

## Commission given up, in the platform stats (SCRUM-469)

The admin overview (`/administrator`, any administrator) has a **"Commission given up (fee waivers)"** card under the
Money table, following the page's period buttons (this week / this month / all time, with the change against the
previous week or month). Per currency it shows the total platform fee not taken because a waiver was in force, split
into **Platform-wide** periods and **Per-counsellor** ones (SCRUM-475), plus an **Unattributed** line, only when
non-zero, for waived amounts stored without their waiver (see the decision log). It comes from `GetPlatformStatsAction`
(`periods.*.current|previous.commissionWaived` = `{total, platformWide, perCounsellor, unattributed}`, minor units per
currency),
cached for 15 minutes like the rest of the figures.

What counts: earnings that aren't reversed and retainer invoice lines unless the invoice failed or the line's earning
was reversed -- so a retainer session counts once billed, before the invoice is paid, unlike the Platform fees
row; each waived amount once (an organisation charge's breakdown and a settled invoice line both copy their
waiver onto the earning, so neither is summed twice -- see the decision log, "SCRUM-469"). A retainer line counts in
the period it was recorded, even if its invoice settles later.

**Test data / try it:** log in as `suspension_demo_normal_admin` (or the super admin `mr_robertamoah`), open
http://localhost:8000/administrator and click **All time**: the card shows **GHS 15.00**
in total (the GHS 10.00 and GHS 5.00 waived on `commission_free_demo_counsellor`'s seeded earnings), all of it
Platform-wide, Per-counsellor GHS 0.00. The seeded money is dated about seven to eight weeks back, so "This week"
shows "No commission given up in this period."; "This month" may show it as the change against last month. If the
figure looks stale, it is the 15-minute cache (`docker compose exec -u www-data php php artisan cache:clear`).

## One counsellor's own periods (SCRUM-475, backend)

A super admin can give one counsellor a commission-free period of their own -- optionally for an approved recognition
(SCRUM-473). In the UI this is on the admin recognition page, `/administrator/recognition` (SCRUM-477): "Also waive
the platform fee" when approving or adding, and a Fee waiver column with **end now** / **delete** for the periods
linked to that month's recognitions (see `counsellor-recognition.md`, "Fee waiver"). Every administrator sees that
column's dates, status and `needsReview`; only a super admin sees the reason and the buttons. A period not linked to a
recognition only shows through the JSON routes below.

- Same field rules as platform-wide (UTC, no backdating, end after the start and now, within 2 years, reason up to
  500). A counsellor's periods can't overlap each other; they may overlap a platform-wide one (whose id is then
  recorded on payments). Only a counsellor who is verified, not suspended, not deleted and not the subject of an open
  or under-review report can be given one, and never the super admin's own counsellor profile. With
  `counsellor_recognition_id` (an approved recognition of theirs, not already linked) the period is linked to it.
- The counsellor is told by a notification (Recognition kind; dates only) and sees "No platform commission on your
  payments between … and …" on their Payouts section and dashboard Earnings card. Nobody else sees it.
- Periods aren't ended automatically if the counsellor is later suspended, loses verification, is reported, or their
  recognition is skipped; every response flags them (`needsReview`, plus `recognitionDecision`) for the admin to
  decide. Ending or deleting a period sends the counsellor nothing; their notice just stops showing it.
- Every create, end and delete, platform-wide or per-counsellor, is recorded in `platform_fee_waiver_events` (a
  deleted period's event keeps its dates and reason).

| Method | URL | Name |
|---|---|---|
| GET | `/administrator/counsellors/{counsellor}/fee-waivers` | `admin.counsellors.fee-waivers.index` (JSON: `counsellor` flags, `waivers`, `events`) |
| POST | `/administrator/counsellors/{counsellor}/fee-waivers` | `admin.counsellors.fee-waivers.store` (`starts_at`, `ends_at`, `reason`, optional `counsellor_recognition_id`) |
| POST | `/administrator/counsellors/{counsellor}/fee-waivers/{waiver}/end` | `admin.counsellors.fee-waivers.end` |
| POST | `/administrator/counsellors/{counsellor}/fee-waivers/{waiver}/delete` | `admin.counsellors.fee-waivers.delete` |

Super admin only (a 403 before validation for anyone else); the POSTs are throttled at 10 a minute, the GET at 60.

**Test data:** `sarah_johnson` has a period for next month linked to her seeded recognition (`RecognitionSeeder`; see
`documentation/seeded-data.md`, "Her own commission-free period"). Log in as the super admin `mr_robertamoah` and
`GET /administrator/counsellors/{her counsellor id}/fee-waivers` to see it; log in as `sarah_johnson` to see the
personal notice on `/counsellor/dashboard`.
