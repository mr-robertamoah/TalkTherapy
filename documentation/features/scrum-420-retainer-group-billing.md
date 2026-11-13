# Retainer billing for group sessions (SCRUM-420, part 1)

SCRUM-420 has three parts. This is the first: **an organisation's retainer now pays for group sessions.**
The other two (the organisation's card paying for group one-offs, and removing the "label only" path) follow.

## What was built
A member whose organisation covers them on a retainer has never been asked to pay for a group. However, a held group
session was never put on the organisation's invoice either, so the organisation paid nothing and the group's
counsellors earned nothing. Now, when a group session is held:
- each covered member is billed to their own organisation's monthly invoice, with **one line per counsellor**
  running the group;
- the group price is **split equally between its counsellors**, and each is paid their own terms with that
  organisation on their part;
- a **fixed or negotiated rate is for the whole session**, so each member's line carries an equal share of it,
  across everyone in the session;
- the platform fee is on the parts billed;
- a counsellor the organisation has no terms with isn't billed to it.

A session is billed once, when it first becomes held; ending it again bills nothing. "Covered members" means those who
had joined before it ended, haven't left, and aren't one of its counsellors. Counsellors likewise: those who had
joined before it ended. Free groups are never billed. Group sessions held before this change stay unbilled.

The organisation admin sees each line on their billing reconciliation page as **"Group session on <date>"**, with the
counsellor and amounts. The member is never named.

## Test data
| Account | What to look at |
|---|---|
| `org_demo_admin` | `/organizations/1/reconciliation` → expand the open (current month) invoice: a "Group session on <yesterday>" line for "Org Retainer Demo Group", with Dr. Org DemoCounsellor, next to the existing one-to-one session line. |
| `org_demo_member` | The one member of "Org Retainer Demo Group" (PAID, per session); their retainer covers it. |

The group comes from the normal seed. If your local database was seeded before this change, it doesn't have it: re-seed,
or create it in tinker. To do it in tinker, make a PAID group run by `org_demo_counsellor`, add `org_demo_member`,
add a session with status `held`, and call `RecordOrganizationInvoiceLineForSessionAction::new()->execute($session)`.

## Try it
1. Log in as `org_demo_admin` and open `/organizations/1/reconciliation`.
2. Under **Retainer Invoices**, press **+** on the current month's open invoice.
3. You should see "Group session on <date>" with Dr. Org DemoCounsellor, USD 20.00 net and USD 3.00 fee:
   - the fixed rate of 20.00 is for the session, and it has one member;
   - the fee is 10% of 30.00.
4. The invoice's "accrued so far" total includes it.

## Deployment notes
- One migration: `organization_invoice_lines.user_id` (backfilled for existing one-to-one lines), with uniqueness moved
  from the session to (session, counsellor, member).
- After deploying, `SELECT COUNT(*) FROM organization_invoice_lines WHERE user_id IS NULL` should be 0.
- To roll back, revert the code **and** run `migrate:rollback --step=1`. Old code on the new key would lose its guard
  against duplicate lines. The rollback fails if a group session already has several lines; check those against
  settled invoices and earnings before removing any.
