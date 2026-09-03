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
| `org_demo_admin` | `password` | Owns "Org Demo Wellness Collective" (id 1 in a fresh seed) as its owner-role admin. Log in and visit `/organizations/{id}/dashboard`. |
| `org_demo_plain_admin` | `password` | A second, plain (non-owner) admin of the same org — log in as this account to see the read-only admin list (no add/promote/demote/remove controls), or log in as `org_demo_admin` to promote/demote/remove this account and exercise the last-owner-protection error (SCRUM-166). |
| `org_demo_counsellor` | `password` | Dr. Org DemoCounsellor — already ACTIVE-affiliated with the org, with agreed compensation (USD 2000 fixed), PLUS a pending compensation-change negotiation (org proposing USD 2500) awaiting their decision — log in and visit `/organizations/mine/dashboard` to accept/reject/counter-offer it (SCRUM-167). |
| `org_demo_applicant` | `password` | Dr. Org DemoApplicant — has a pending `ORGANIZATION_COUNSELLOR_APPLICATION_REQUEST` to the org (no affiliation row yet — distinct from the already-affiliated counsellor above, per AC7's "pending Request vs. pending affiliation" distinction). |
| `org_demo_member` | `password` | Org DemoMember — already ACTIVE member with a retainer billing config. Log in and visit `/organizations/mine/dashboard` to see it under "My Memberships" (SCRUM-168). |
| `org_demo_member_applicant` | `password` | Org DemoMemberApplicant — has a pending `ORGANIZATION_MEMBER_APPLICATION_REQUEST` to the org (no membership row yet, same distinction as above). |
| `org_demo_member_invitee` | `password` | Org DemoMemberInvitee — has a pending `ORGANIZATION_MEMBER_INVITE_REQUEST` (org-initiated, no membership row yet) — accept it via the account dropdown's "Requests" menu to exercise SCRUM-168's AC2. |

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
