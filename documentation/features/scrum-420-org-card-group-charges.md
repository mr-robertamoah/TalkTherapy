# The organisation's card pays for group places (SCRUM-420, part 2)

SCRUM-420 has three parts. Part 1 made a retainer pay for held group sessions. Parts 2 and 3: **an organisation's
saved card now pays for a pay-per-use member's place in a group**, as it already did for a one-to-one therapy, the
member sees it as "Sponsored", and a refund reverses every counsellor's earning.

## What was built
Before, a group "my organisation pays" charged the **member's own card** at the group's price, with the organisation
recorded only as a label, and nobody earned anything from it. Now:
- the organisation's card is charged **once per member**, for the whole group (pay per therapy) or for one of its
  sessions (pay per session);
- the price is split equally between the group's active counsellors, and each is paid their own terms with the
  organisation on their part. A fixed rate is for the whole group or session, divided by the group's maximum size
  (so organisations never pay more than the rate together). The platform fee is on the price;
- a member can't have their organisation pay for a session that ended before they joined, or one that didn't take
  place;
- if any active counsellor has no terms with the organisation, or it has no card, the payment is **refused**. The
  member's card is never charged instead;
- one member's charge (paid, still being confirmed, or in progress) never blocks another member's;
- each charge records how it splits between the counsellors, and their earnings (one each) are made from that;
- on the organisation's reconciliation page, a group row lists every counsellor it paid with the totals, and a payout
  status of "mixed" when only some have been paid out;
- the member who paid (only them) sees the group as **Sponsored** ("Paid by <org>" on hover), on its card and on its
  page's details tab (part 3);
- refunding a member's place reverses every counsellor's pending earning from it, flags one already paid out, and
  tells the organisation's admins (part 3).

There's no "my organisation pays" button in the app yet (the API takes an `organizationId`), so no member screen
changes.

## Test data
| Account | What to look at |
|---|---|
| `org_demo_admin` | `/organizations/1/reconciliation`, **Financed Sessions (Pay-Per-Use)**: a "Group therapy (started <date>)" row, USD 5.00, with Dr. Org DemoCounsellor, share USD 2.00 and fee USD 3.00, payout "not yet earned". |
| `org_demo_payperuse_member` | Started "Org Pay-Per-Use Demo Group" (PAID, per therapy, USD 30), run by `org_demo_counsellor`; it shows them "Sponsored". |

These come from the normal seed. A database seeded before this change doesn't have them: re-seed, or create the group,
the success transaction (reference starting `org_charge_`, with `breakdown`) and its earning in tinker, as the seeder
does.

## Try it
1. Log in as `org_demo_admin` and open `/organizations/1/reconciliation`.
2. Under **Financed Sessions (Pay-Per-Use)**, find the "Group therapy" row. It should show:
   - Dr. Org DemoCounsellor;
   - amount USD 5.00, share USD 2.00 and fee USD 3.00 (the counsellor's fixed USD 20 for the group over its 10
     places, plus 10% of USD 30);
   - payout "not yet earned" (its earning has not been paid out yet).
3. Log in as `org_demo_payperuse_member`, open "Org Pay-Per-Use Demo Group" and its **details** tab: a "Sponsored"
   badge, "Paid by Org Demo Wellness Collective" on hover. Its card on their home page has it too. Log in as
   `org_demo_counsellor` and the same group shows no badge.
4. The charge itself runs against Paystack, so it's covered by tests (`tests/Unit/ChargeOrganizationForGroupTest.php`, refunds in `tests/Unit/GroupOrgChargeRefundTest.php`)
   rather than a screen.

## Deployment notes
- One migration: a nullable JSON `transactions.breakdown`. Earlier transactions keep it null: their earnings work as
  before.
- To roll back, revert the code and **keep the column** (old code ignores it). Never drop it while this code is live:
  every organisation charge writes it. Before rolling back, check for group organisation charges still pending
  (`organization_id` and `breakdown` set, status pending): old code makes no earnings for a group charge, so their
  counsellors would need earnings made by hand. Rolling back also brings back charging the member's own card for a
  group, with the organisation as a label.
- The stale-charge sweep now also checks whole-group charges.
- Watch the logs for "the recorded breakdown does not add up to the amount charged": nothing is paid out for that
  charge until it's reconciled by hand. It only reaches `laravel.log` today; a log alarm on it is worth adding.
- Restart the queue workers (`queue:restart`, as the deploy script does): earnings are made in the webhook job.
