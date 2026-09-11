# Seeded Test Data

Reference for what's in the local database after seeding, so you can log in and exercise features
through the UI without creating accounts by hand. Regenerate at any time with:

```bash
docker exec talktherapy-php php artisan migrate:fresh --seed
```

This drops and recreates all tables, then runs `database/seeders/DatabaseSeeder.php`. It's
destructive to whatever is currently in the local DB — fine for dev, don't run it against
anything you care about keeping.

## Logging in

The login page (`/login`) has a "Login with username" checkbox that toggles between an email
field and a username field — either works with the same password for every seeded account below.

## Super Admin

| Field | Value |
|---|---|
| Username | `mr_robertamoah` |
| Email | `mr_robertamoah@yahoo.com` |
| Password | value of `SUPER_PASSWORD` in `.env.docker` (currently `itisme2025`) |

Has an `Administrator` record with `type = super` — use this account for `/administrator` and
anything admin-gated.

## Demo users

All seeded demo users share the password **`password`**. Email is already verified
(`email_verified_at` set) for every one of them, so none of the email-verification flow blocks
login — if you specifically need to test *that* flow, register a fresh account instead (see
"Testing the registration/verification flow" below).

The first 6 are also **counsellors** (verified, with a profile, languages, cases, and religion
already attached); the last 4 are plain users.

| # | Username | Email | Name | Counsellor? |
|---|---|---|---|---|
| 1 | `sarah_johnson` | sarah.johnson@example.com | Sarah Johnson | Yes — Dr. Sarah Johnson (#1) |
| 2 | `michael_chen` | michael.chen@example.com | Michael Chen | Yes — Dr. Michael Chen (#2) |
| 3 | `emma_williams` | emma.williams@example.com | Emma Williams | Yes — Dr. Emma Williams (#3) |
| 4 | `david_brown` | david.brown@example.com | David Brown | Yes — Dr. David Brown (#4) |
| 5 | `lisa_anderson` | lisa.anderson@example.com | Lisa Anderson | Yes — Dr. Lisa Anderson (#5) |
| 6 | `james_wilson` | james.wilson@example.com | James Wilson | Yes — Dr. James Wilson (#6) |
| 7 | `maria_garcia` | maria.garcia@example.com | Maria Garcia | No |
| 8 | `john_davis` | john.davis@example.com | John Davis | No |
| 9 | `amy_taylor` | amy.taylor@example.com | Amy Taylor | No |
| 10 | `robert_miller` | robert.miller@example.com | Robert Miller | No |

A counsellor logs in with the same account as the underlying user (there's no separate
counsellor login) — e.g. log in as `sarah_johnson` / `password` to act as Dr. Sarah Johnson.

## Reference data

Seeded once, shared across all users: 10 languages, 8 religions, 12 therapy cases (Anxiety,
Depression, Trauma/PTSD, etc.), 10 professions, 5 licensing authorities.

## Sample content (regenerated randomly on every reseed — IDs below are illustrative)

After a fresh seed you'll get roughly:
- **5 therapies**, owned by the 4 non-counsellor users, each assigned a random counsellor from
  the 6 above, with 2-4 topics and 2-5 sessions each (mixed `pending`/`held`/`in_session`/
  `abandoned` status) — some `anonymous`, some `public`, randomly, so you can find both cases to
  test against.
- **3-5 group therapies**, each with 3-8 participating users and 1-3 counsellors.
- **3-6 discussions** between counsellors, tied to a random therapy.
- **~20 posts** from counsellors (mental-health articles) plus a few from regular users, with
  likes and comments from other seeded users.

Additionally, three **named, deterministic** records exist on every reseed specifically for
exercising the dedicated chat pages (see `documentation/features/scrum-20-live-chat.md`): a
`Chat Demo Individual Therapy` and `Chat Demo Group Therapy` (both `IN_SESSION` with a live
session, client `maria_garcia` / counsellor `sarah_johnson`), and a `Chat Demo Discussion`
(`IN_SESSION`, between `sarah_johnson` and `michael_chen`, with existing messages).
`Chat Demo Group Therapy`'s live session has **two** active counsellors (`sarah_johnson` and
`michael_chen`) specifically so cross-counsellor session-notes isolation (see
`documentation/features/scrum-21-session-notes.md`) is browser-verifiable, not just Pest-covered:
log in as either counsellor on `/group-therapies/{id}/chat` and confirm you never see the other's
private notes.

`Chat Demo Group Therapy` is also the fixture for anonymity masking (see
`documentation/features/scrum-71-anonymity-masking.md`): the group itself is **not** anonymous,
but `john_davis` is attached as a member with a per-member `anonymous = true` pivot row, and has
an existing message in the group's live session — log in as anyone else in the group
(`maria_garcia` or `sarah_johnson`) to see that message's sender masked, or as `john_davis` to see
it show their own real identity.

A fourth named, deterministic record exists for the group-therapy membership/join flow (see
`documentation/features/scrum-72-group-therapy-membership.md`): `Membership Request Demo Group
Therapy`, created by `maria_garcia` with `allow_anyone = false`, with a **PENDING** membership
request already sent by `amy_taylor`. Log in as `maria_garcia` to see and accept/reject the
pending request, or as `amy_taylor` to see your own request still pending.

Query the actual current values anytime rather than trusting numbers to stay the same across
reseeds:

```bash
docker exec talktherapy-php php artisan tinker --execute="
foreach (\App\Models\Therapy::with('addedby','counsellor')->get() as \$t) {
    echo \$t->id.' | '.\$t->name.' | owner='.\$t->addedby->username.' | counsellor='.\$t->counsellor->name.PHP_EOL;
}"
```

## Counsellor account deletion (SCRUM-134)

Two dedicated accounts, kept separate from the 6 main demo counsellors above since those are
woven into therapies/group therapies/discussions/chat demo data used by many other features:

| Username | Password | Purpose |
|---|---|---|
| `deletable_counsellor` | `password` | Dr. Deletable Counsellor — no pending sessions, therapies, or affiliations. Log in and visit their counsellor profile page to delete the account; it should succeed. |
| `blocked_counsellor` | `password` | Dr. Blocked Counsellor — has an in-session therapy (`Counsellor Deletion Demo Therapy`, client: `blocked_counsellor_client`). Deletion should be rejected by `EnsureCanDeleteCounsellorAction`'s eligibility gate. |

Log in as `mr_robertamoah` (the super admin) and visit `/administrator` → counsellors to test
admin-triggered deletion of either account instead.

## Payment UI (SCRUM-157/158)

Dedicated accounts and therapies for testing the Pay Now flow, since the random demo therapies
above only *might* land on `PAID` and never deterministically pair the two payment models
(`PER_THERAPY`/`PER_SESSION`) with a specific client/counsellor:

| Username | Password | Purpose |
|---|---|---|
| `payment_demo_client` | `password` | Owns both payment demo therapies below. |
| `payment_demo_counsellor` | `password` | Assigned counsellor on both — log in as this account to confirm no Pay control ever renders for a counsellor. |

| Therapy | Payment model | Notes |
|---|---|---|
| Payment Demo Therapy (Per Therapy) | `PER_THERAPY`, USD 150 | Pay Now lives on the "payment details" tab. |
| Payment Demo Therapy (Per Session) | `PER_SESSION`, USD 50 | Has one seeded session ("Payment Demo Session"), always within 5 minutes of its start time so it's immediately the therapy's active session — Pay Now lives in the "Session Actions" modal (double-click the expanded active-session panel to open it). |

## Refund request UI (SCRUM-250)

Dedicated accounts and already-**SUCCESS**-paid therapies for testing the client refund-request
control (the payment demo data above is deliberately left unpaid, to test the Pay Now flow
itself):

| Username | Password | Purpose |
|---|---|---|
| `refund_demo_client` | `password` | Owns all four refund demo therapies below. |
| `refund_demo_counsellor` | `password` | Assigned counsellor on all four — log in as this account to confirm no "request a refund" control ever renders for a counsellor, and to see the "Refunded" payment-status label (SCRUM-253). |

| Therapy | Payment model | Notes |
|---|---|---|
| Refund Demo Therapy (Per Therapy) | `PER_THERAPY`, USD 120, transaction already `SUCCESS` | "request a refund" lives on the "payment details" tab, right below the "Paid" label. |
| Refund Demo Therapy (Per Session) | `PER_SESSION`, USD 40, transaction already `SUCCESS` | Has one seeded session ("Refund Demo Session"), always within 5 minutes of its start time so it's immediately the therapy's active session — "request a refund" lives in the "Session Actions" modal. |
| Refund Demo Therapy (Pending Admin Review) | `PER_THERAPY`, USD 80, transaction already `SUCCESS` | Already has a `PENDING` refund request on it (SCRUM-251) — visit `/administrator/refund-requests` as the super admin to see and act on it immediately, with no manual "ask" step needed first. |
| Refund Demo Therapy (Refunded) | `PER_THERAPY`, USD 100, transaction already `SUCCESS` | Already has a `SUCCESS` `Refund` row on it (SCRUM-253) — the "payment details" tab shows "Refunded" (not "Paid") for both `refund_demo_client` and `refund_demo_counsellor`, and no "request a refund" control renders for either. |

## Group therapy per-member payment (SCRUM-259, TT-7.4d)

Dedicated accounts and PAID group therapies for testing per-member payment/refund/roster
behavior, since the random demo group therapies never deterministically produce a PAID group with
a known payment split across members:

| Username | Password | Purpose |
|---|---|---|
| `group_payment_demo_member_paid` | `password` | Already has a `SUCCESS` transaction on both groups below. |
| `group_payment_demo_member_unpaid` | `password` | Has never paid on either group. |
| `group_payment_demo_counsellor` | `password` | Assigned counsellor on both groups — log in as this account to see the per-member payment roster (SCRUM-261). |

| Group Therapy | Payment model | Notes |
|---|---|---|
| Group Payment Demo (Per Therapy) | `PER_THERAPY`, USD 100 | "payment details" tab shows `group_payment_demo_member_paid` as "Paid" and `group_payment_demo_member_unpaid` with a real "pay now" control -- each scoped to their own status, not each other's. |
| Group Payment Demo (Per Session) | `PER_SESSION`, USD 50 | Has one seeded session ("Group Payment Demo Session"), always within 5 minutes of its start time so it's immediately the group's active session -- same per-member split, in the "Session Actions" modal. |

## Group therapy strict payment gate / late-joiner (SCRUM-270, TT-7.5b)

A dedicated, deterministic strict-gated GroupTherapy for testing the payment-required
redirect/banner and the late-joiner "free historical access" exemption -- the group above
(SCRUM-259) is PAID but trust-based (`strictPaymentGate: false`), so it never blocks access on
its own.

| Username | Password | Purpose |
|---|---|---|
| `group_strict_gate_demo_counsellor` | `password` | Active counsellor on the group below -- log in as this account to confirm a counsellor is never gated, and to toggle the payment-gate settings on the "payment details" tab (SCRUM-269). |
| `group_strict_gate_demo_member_unpaid` | `password` | Joined at group creation, never paid -- visiting the group's page redirects to Home with the payment-required banner (SCRUM-266). |
| `group_strict_gate_demo_late_joiner` | `password` | Joined 5 days ago (well after the group and its "before" session below), never paid -- can still open the "Group Strict Gate Demo Session (Before Late Joiner)" session's chat for free, but is blocked from "...( After Late Joiner)" the same way `member_unpaid` is blocked from everything (SCRUM-267). |

| Group Therapy | Payment model | Notes |
|---|---|---|
| Group Strict Payment Gate Demo | `PER_THERAPY`, USD 100, `strictPaymentGate: true`, `allowFreeHistoricalAccess: true` | Two seeded sessions, one dated 20 days ago (before the late joiner's own join) and one dated yesterday (after it) -- log in as `group_strict_gate_demo_late_joiner` and compare access to each to see the late-joiner exemption directly. |

## Counsellor payout (SCRUM-228)

A dedicated counsellor with pending earnings but no payout destination yet, for testing the
onboarding-then-withdraw golden path on the counsellor profile's "Payouts" section, since none of
the demo data above deterministically produces a `CounsellorEarning` row to withdraw:

| Username | Password | Purpose |
|---|---|---|
| `payout_demo_client` | `password` | Owns the two seeded therapies whose transactions back the earnings below. |
| `payout_demo_counsellor` | `password` | Dr. Payout DemoCounsellor — has two pending `CounsellorEarning` rows in GHS (net GHS 81.00 + GHS 54.00 = GHS 135.00 total), above the seeded GHS minimum payout threshold, but no `CounsellorPayoutAccount` yet. Log in and visit their own counsellor profile (`/counsellor/{id}`) to see the Payouts section, onboard a bank/mobile-money destination, and (once a real `PAYSTACK_SECRET_KEY` is configured in `.env.docker` — see `documentation/features/scrum-110-paystack-payments.md`) trigger a withdrawal. |

## Organization admin dashboard (SCRUM-165)

A deterministic org (both provider and consumer) exercising every section of the org-admin
dashboard, since the random demo data has no organizations at all:

| Username | Password | Purpose |
|---|---|---|
| `org_demo_admin` | `password` | Owns "Org Demo Wellness Collective" (id 1 in a fresh seed) as its owner-role admin. Log in and visit `/organizations/{id}/dashboard`. Also has a payment method already on file (masked `**** 4242`, GHS) — visit `/organizations/1/payment-instrument` (SCRUM-240) to see the "current payment method" state; registering/replacing one for real needs a live Paystack checkout round trip, not completable in this dev environment (no `PAYSTACK_SECRET_KEY` configured). |
| `org_demo_plain_admin` | `password` | A second, plain (non-owner) admin of the same org — log in as this account to see the read-only admin list (no add/promote/demote/remove controls), or log in as `org_demo_admin` to promote/demote/remove this account and exercise the last-owner-protection error (SCRUM-166). |
| `org_demo_counsellor` | `password` | Dr. Org DemoCounsellor — already ACTIVE-affiliated with the org, with agreed compensation (USD 2000 fixed), PLUS a pending compensation-change negotiation (org proposing USD 2500) awaiting their decision — log in and visit `/organizations/mine/dashboard` to accept/reject/counter-offer it (SCRUM-167). |
| `org_demo_applicant` | `password` | Dr. Org DemoApplicant — has a pending `ORGANIZATION_COUNSELLOR_APPLICATION_REQUEST` to the org (no affiliation row yet — distinct from the already-affiliated counsellor above, per AC7's "pending Request vs. pending affiliation" distinction). |
| `org_demo_member` | `password` | Org DemoMember — already ACTIVE member with a retainer billing config. Log in and visit `/organizations/mine/dashboard` to see it under "My Memberships" (SCRUM-168). Also owns two PAID therapies with `org_demo_counsellor` ("Org Retainer Demo Therapy (Per Therapy/Per Session)", with an immediately-active session on the latter) exercising the client-facing org-billing disclosure that replaces the Pay control (SCRUM-242) — visit `/therapies/{id}`'s "payment details" tab, or the per-session therapy's session-actions modal. |
| `org_demo_member_applicant` | `password` | Org DemoMemberApplicant — has a pending `ORGANIZATION_MEMBER_APPLICATION_REQUEST` to the org (no membership row yet, same distinction as above). |
| `org_demo_member_invitee` | `password` | Org DemoMemberInvitee — has a pending `ORGANIZATION_MEMBER_INVITE_REQUEST` (org-initiated, no membership row yet) — accept it via the account dropdown's "Requests" menu to exercise SCRUM-168's AC2. |
| `org_demo_payperuse_member` | `password` | Org DemoPayPerUseMember — active member on PAY_PER_USE billing, with one fully-resolved financed Therapy (Transaction success, `CounsellorEarning` paid out) — log in as `org_demo_admin` and visit `/organizations/1/reconciliation` (SCRUM-241) to see this row in the "Financed Sessions" table. |

Also for SCRUM-241 (org-admin reconciliation view): the demo org has a settled retainer invoice
(last calendar month, one line against the existing "Org Retainer Demo Session") and an open,
still-accruing one (current calendar month, a second held session seeded specifically for it) —
visit `/organizations/1/reconciliation` as `org_demo_admin` to see both, and expand either row to
see its lines.

For SCRUM-245 (manual billing-suspension lift + failed-invoice retry): a separate "Suspended Demo
Collective" org (`suspended_demo_admin` / `password` owns it) is seeded currently billing-suspended
with a failed retainer invoice and a payment instrument on file — log in as the super admin
(`mr_robertamoah`) and visit `/administrator/organization-billing` to retry its settlement or lift
the suspension.

For SCRUM-285 (TT-3.1e-f, guardian video-consent UI): a deterministic minor client, their
guardian, and a counsellor, on an individual therapy already set to `PER_THERAPY` consent mode
with an immediately in-progress online session, deliberately left WITHOUT a grant:

| Username | Password | Purpose |
|---|---|---|
| `video_consent_demo_minor` | `password` | The therapy's own client (`dob` set to 15 years ago). Log in and try "join video" on "Video Consent Demo Therapy" to see the consent-required banner before the guardian approves. |
| `video_consent_demo_guardian` | `password` | Guardian of the minor above. Log in, open the therapy's "video consent" tab to approve/revoke consent and view the audit trail. |
| `video_consent_demo_counsellor` | `password` | Dr. VideoConsent DemoCounsellor — the assigned counsellor, who can also switch the consent mode (PER_THERAPY/PER_SESSION) from the same tab, but cannot approve/revoke consent itself. |

## Testing the registration/verification flow

The 11 seeded accounts above all skip email verification. To test registration or the
verification email itself, register a new account through the UI with any email address — the
app sends real emails through **Mailpit** in this dev environment, not out to the internet.
View them at **http://localhost:8025**.

## Keeping this file current

When a feature needs its own seed data to be testable via the UI (a new model, a new state that
doesn't exist in the current seeder, a specific edge case), add it to `DatabaseSeeder.php` (or a
dedicated seeder it calls) rather than requiring manual setup, and document it in that feature's
`documentation/features/<feature-slug>.md` — see "Feature documentation" in `CLAUDE.md`.
