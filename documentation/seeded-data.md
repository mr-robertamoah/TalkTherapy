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
An `Editable Demo Discussion` (`PENDING`, three days out at 10:00–10:40, owned by `sarah_johnson`
with `michael_chen`, on the `Chat Demo Individual Therapy`) is there for trying the update-discussion
form (SCRUM-379).
The demo sessions and the demo discussion are live for **one hour after seeding**. After that their end time has passed, and the chat pages have nothing to send into. To make them live again without reseeding, run `docker compose exec php php artisan demo:refresh-chat`.

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

## Counsellor verification request (SCRUM-301)

| Username | Password | Purpose |
|---|---|---|
| `unverified_counsellor` | `password` | Dr. Unverified Counsellor — not yet verified, with a verified email, a phone and an adult date of birth. Their counsellor profile shows "request verification", which needs a licensing authority, the licence number, a copy of the licence, and a national ID number or scan. |

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
| `refund_demo_client` | `password` | Owns all five refund demo therapies below. |
| `refund_demo_counsellor` | `password` | Assigned counsellor on all five — log in as this account to confirm no "request a refund" control ever renders for a counsellor, and to see the "Refunded" payment-status label (SCRUM-253). |

| Therapy | Payment model | Notes |
|---|---|---|
| Refund Demo Therapy (Per Therapy) | `PER_THERAPY`, USD 120, transaction already `SUCCESS` | "request a refund" lives on the "payment details" tab, right below the "Paid" label. |
| Refund Demo Therapy (Per Session) | `PER_SESSION`, USD 40, transaction already `SUCCESS` | Has one seeded session ("Refund Demo Session"), within 5 minutes of its start time *at seed time* so it's immediately the therapy's active session (it fails once its window passes -- reseed to get it back) — "request a refund" lives in the "Session Actions" modal. |
| Refund Demo Therapy (Pending Admin Review) | `PER_THERAPY`, USD 80, transaction already `SUCCESS` | Already has a `PENDING` refund request on it (SCRUM-251) — visit `/administrator/refund-requests` as the super admin to see and act on it immediately, with no manual "ask" step needed first. |
| Refund Demo Therapy (Refunded) | `PER_THERAPY`, USD 100, transaction already `SUCCESS` | Already has a `SUCCESS` `Refund` row on it (SCRUM-253) — the "payment details" tab shows "Refunded" (not "Paid") for both `refund_demo_client` and `refund_demo_counsellor`, and no "request a refund" control renders for either. |
| Refund Demo Therapy (Public) | `PER_THERAPY`, USD 50, transaction already `SUCCESS`, **public** | SCRUM-368: the "payment details" tab shows "Paid" to `refund_demo_client`, `refund_demo_counsellor` and admins; any other signed-in account browsing it sees no payment status. |

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

## Video call (SCRUM-279, TT-3.1f)

A plain adult client + counsellor pair, no guardian-consent complexity at all (the
`video_consent_demo_*` accounts above are deliberately minor-focused) — for simply trying the
base "join video" flow, across whichever provider `VIDEO_PROVIDER` is currently configured to:

| Username | Password | Purpose |
|---|---|---|
| `video_call_demo_client` | `password` | An adult client. Log in and try "join video" on "Video Call Demo Therapy" — no consent gate to clear first. |
| `video_call_demo_counsellor` | `password` | The assigned counsellor. |

"Video Call Demo Therapy" already has an immediately in-progress, online session. See
`documentation/features/scrum-26-video-calling.md` for the full feature write-up, including the
known limitation that this dev environment has no real Daily/Chime credentials configured, so a
live media connection can't be manually verified end-to-end without populating them first.

## Group video call + participant removal + speaking permission (SCRUM-308/309/310, TT-3.2a/b/c; SCRUM-327)

A FREE, non-strict-gated GroupTherapy with one active counsellor, its own (non-minor) creator, and
three ordinary members (adult, minor, anonymous) — for trying group "join video", the
counsellor-only "remove participant" control, and raise-hand / let-speak moderation:

| Username | Password | Purpose |
|---|---|---|
| `group_video_call_demo_counsellor` | `password` | The group's assigned counsellor. Sees "join video" and, once in the call, a "remove" control on every other participant's tile. |
| `group_video_call_demo_creator` | `password` | The group's own creator (an adult client). Sees "join video" too (per TT-3.2's own v1 scope: counsellors, plus the creator, optionally). |
| `group_video_call_demo_member` | `password` | An ordinary adult member. Sees "join video" and joins receive-only (watching); can raise a hand and, once a counsellor lets them speak, unmute (with a one-time "you'll be heard and seen" warning) and turn the camera on (SCRUM-327). |
| `group_video_call_demo_minor_member` | `password` | An ordinary member who is a minor. Joins receive-only like any member, but is never offered "raise hand" (minors can't be granted speaking yet, SCRUM-324/329). |
| `group_video_call_demo_anonymous_member` | `password` | An ordinary member who joined anonymously. Shown as "Anonymous" in the call; if let speak, gets audio only — no camera button. |
| `group_video_call_demo_minor_creator` | `password` | Creator of a SEPARATE group ("Group Video Call Demo (Minor Creator)"), created while a minor. "Join video" is still visible (intentional — see the feature doc), but clicking it is cleanly blocked with a 422. |
| `group_video_call_demo_guardian` | `password` | Guardian of both minors above (SCRUM-288): without an active guardian their therapies would be paused. |

"Group Video Call Demo" already has an immediately in-progress, online session. Same known
limitation as the 1:1 video call fixture above (no real Daily/Chime credentials in this dev
environment, so join succeeds up to minting/room-creation only) — see
`documentation/features/scrum-27-group-therapy-video.md`.

## Dob-change approval (SCRUM-294, TT-4.10e)

A minor's boundary-crossing dob edit is deferred to a `dobChange`-type Request needing approval,
surfaced through the existing generic Requests list/modal (the same one used for guardianship,
counsellor-verification, etc.) rather than a new dedicated UI.

**Guardian-addressed path**: reuses `video_consent_demo_minor` / `video_consent_demo_guardian`
above (the minor already has an active guardian) — no separate seed needed. Log in as the minor,
edit dob on Profile → Update, confirm the pending-approval banner and unchanged displayed dob; log
in as the guardian, open Requests, and accept/reject.

**No-guardian / any-admin path**: a minor with no guardian at all and an already-pending null-`to`
dobChange request, so this path is reachable without hand-building data through `tinker` first.

| Username | Password | Purpose |
|---|---|---|
| `dobchange_demo_no_guardian` | `password` | Minor (`dob` set to 15 years ago) with no Guardianship record and an already-PENDING dobChange request (`data.newDob` set to 30 years ago). Log in as the super admin (`mr_robertamoah`) and open Requests to see and act on it immediately. |

**Multi-guardian path** (TT-4.10f/SCRUM-295): a minor with TWO active guardians and an
already-pending dobChange request addressed to only the first, so "any one of a ward's guardians
may respond" is reachable without hand-building data through `tinker` first.

| Username | Password | Purpose |
|---|---|---|
| `dobchange_demo_multi_guardian` | `password` | The minor (`dob` set to 15 years ago), with an already-PENDING dobChange request addressed to `dobchange_demo_first_guardian`. |
| `dobchange_demo_first_guardian` | `password` | The guardian the request is addressed to (`to`) — can see and act on it via the normal `whereTo` match. |
| `dobchange_demo_second_guardian` | `password` | A second, equally-valid guardian of the same minor, NOT named in the request's `to` — log in as this account and open Requests to confirm the request is still visible and actionable. |

## Age verification (SCRUM-289/TT-4.11)

A user can optionally submit a self-attestation (+ an optional supporting document) strengthening
their claimed `dob`, reviewed by any admin through the same generic Requests list/modal. Before
this seed existed, no demo user had a `dob` set at all, so exercising this golden path required
hand-picking a dobChange fixture (`dobchange_demo_no_guardian`, built for a different feature) or
building a user via `tinker` first.

| Username | Password | Purpose |
|---|---|---|
| `age_verification_demo_user` | `password` | Adult (`dob` set to 25 years ago) with an already-PENDING `ageVerification` request (self-attestation only, no document). Log in as the super admin (`mr_robertamoah`) and open Requests to see and act on it. To try the document-upload path, log in as this account instead, go to Profile → Age Verification → submit another statement, and attach any small image/PDF. |

## Testing the registration/verification flow

The 11 seeded accounts above all skip email verification. To test registration or the
verification email itself, register a new account through the UI with any email address — the
app sends real emails through **Mailpit** in this dev environment, not out to the internet.
View them at **http://localhost:8027**.

## Keeping this file current

When a feature needs its own seed data to be testable via the UI (a new model, a new state that
doesn't exist in the current seeder, a specific edge case), add it to `DatabaseSeeder.php` (or a
dedicated seeder it calls) rather than requiring manual setup, and document it in that feature's
`documentation/features/<feature-slug>.md` — see "Feature documentation" in `CLAUDE.md`.

## Report review demo (SCRUM-340)

"Report Demo Group Therapy" is created by `maria_garcia` and counselled by Dr. Emma Williams. Its
members `amy_taylor` and `robert_miller` are anonymous. It has one finished session, "Report Demo
Session", with:

- public messages;
- a confidential message from amy_taylor to the counsellor, and the counsellor's confidential reply;
- a confidential message from robert_miller (which must stay hidden from a reviewer);
- a confidential message from amy_taylor that was deleted for everyone.

`maria_garcia` has filed three reports on the group:

- **open, naming amy_taylor:** the conversation can be read;
- **open, naming nobody:** record a reported person first;
- **dismissed, with history:** can be reopened.

Review them as the super admin at `/administrator/reports`. See
`documentation/features/scrum-340-338-complaint-review-and-license-retention.md`.


## Suspension demo (SCRUM-359)

All of these share the password **`password`**.

| Username | What it is |
|---|---|
| `suspension_demo_admin` | A second **super admin**. It "applied" the seeded suspensions, so the main super admin (`mr_robertamoah`) is the one who can decide the waiting appeal — the admin who applied a suspension never may. |
| `suspension_demo_client` | A client with therapies with the two counsellors below. Has an old, already-ended account suspension (7 days, two weeks ago). |
| `suspension_ready_counsellor` | A counsellor whose report (filed by `suspension_demo_client`, "[Suspension demo: ready to suspend]") is resolved with "suspension recommended" and nobody suspended yet. Its therapy has an upcoming session in 3 days, and `suspension_waiting_client` has a pending request to them — suspending them fails the session and declines the request. |
| `suspension_waiting_client` | A client waiting on a request to `suspension_ready_counsellor`. |
| `suspension_demo_normal_admin` | A **normal** (not super) admin: sees `/administrator/suspensions` but can't apply, lift or decide anything. |
| `suspended_counsellor_demo` | A counsellor whose **counsellor role** is suspended for 30 days. Signs in normally and can use TalkTherapy as a client; is hidden from counsellor search. |
| `suspended_client_demo` | A client whose **whole account** is suspended until lifted, with an **appeal waiting**. Every page sends them to `/suspended`. |

Try it as `mr_robertamoah`: open `/administrator/reports?status=resolved`, open the
"ready to suspend" report and suspend the counsellor; or go to `/administrator/suspensions` to
decide the appeal or lift a suspension. See
`documentation/features/scrum-359-362-suspension-and-report-hardening.md`.

## Admin read log (SCRUM-360)

Three entries by `mr_robertamoah` (reading "Report Demo Session"'s messages, a message's replies,
and a licence document when one is seeded), so `/administrator/content-accesses` has something to
show. Reading any private therapy you aren't part of adds more. `suspension_demo_normal_admin` can't
open the page. See
`documentation/features/scrum-360-361-363-316-254-admin-read-log-privacy-and-suspension-followups.md`.

## Closed accounts (SCRUM-367)

Two closed (deleted) accounts, password **`password`** (they can't sign in while closed):

| Username | What it is |
|---|---|
| `closed_account_demo_old` | Closed 31 days ago. The next run of the daily anonymisation job erases its personal details; it then shows as "Deleted user". |
| `closed_account_demo_recent` | Closed yesterday. Kept until its 30 days are up. |

Run the job by hand with
`docker compose exec php php artisan tinker --execute="App\Services\AppService::new()->anonymiseClosedAccounts();"`.
See `documentation/features/scrum-367-privacy-access-hardening.md`.

## Therapy paused for a missing guardian (SCRUM-288)

A minor (15) whose only guardian was removed, so their individual therapy and their group are
paused: they (and the counsellor of the 1:1) can read everything, but every write is refused with
`guardian_required`. All passwords are **`password`**.

| Username | What it is |
|---|---|
| `paused_minor` | Jordan Avery, 15, no guardian. Client of "Paused Minor Demo Therapy" (a held session with two messages to read, and an upcoming session that stays booked but can't be started) and a member of "Paused Minor Demo Group" (a session in progress). Adding a guardian from the profile page resumes both. |
| `paused_minor_demo_counsellor` | Counsellor of the paused 1:1 (read-only there too) and of the group (unaffected there). |
| `paused_minor_demo_group_member` | An adult member of the group, who carries on as normal. |

Note: a missing date of birth counts as a minor (SCRUM-288), so a client with no date of birth and no
guardian is paused too. The seed therefore gives every demo adult that had none an adult date of
birth (30 years ago), so the demos keep working -- except `no_dob_demo_user` below. Run
`docker compose exec -u www-data php php artisan guardians:report-unguarded-minors` to list anyone paused.

## Safeguarding exceptions (SCRUM-381)

Built on the SCRUM-288 demo above. All passwords are **`password`**.

| Username | What it is |
|---|---|
| `paused_minor` | Still paused. `paused_minor_demo_counsellor` has a **pending request** for a safeguarding exception for them on "Paused Minor Demo Therapy" (proposed: 14 days). |
| `safeguarding_exception_minor` | Casey Morgan, 15, no guardian, carrying on under an **active exception that ends in 2 days** (granted by `mr_robertamoah` 12 days ago). Client of "Safeguarding Exception Demo Therapy" (counsellor: `paused_minor_demo_counsellor`) and an **anonymous** member of "Paused Minor Demo Group" -- its counsellor sees them only as "Anonymous". The next hourly reminder sweep sends the 3-day heads-up. |
| `mr_robertamoah` | The super admin: approves/rejects the request, grants, renews and ends exceptions at `/administrator/safeguarding-exceptions` (or from the requests list). |
| `suspension_demo_normal_admin` | A normal admin: sees the page and the audit list, but can't grant, renew, end or answer a request. |

See `documentation/features/scrum-381-safeguarding-exception.md`.

## An account with no date of birth (SCRUM-382)

| Username | What it is |
|---|---|
| `no_dob_demo_user` | NoDob Demo, email verified, no date of birth, no therapy or group. Password **`password`**. Opening "create a therapy" shows "You must set your date of birth before you can create a therapy." Setting an adult date of birth on the profile page lets them create one. It is the one seeded account the seed leaves without a date of birth, so it shows up (with nothing open) in `guardians:report-unguarded-minors`. |


## Leaving a group, and group invitation links (SCRUM-73, SCRUM-383)

"Leave Demo Group Therapy" -- private and **PAID** (50 USD per therapy, 70% to counsellors; a FREE
group must be public, so the earlier FREE-and-private seed couldn't be edited), with no strict payment
gate, `allow_anyone = false` (a rejoin needs the creator's approval again), not anonymous as a group,
in session, 5 member places, 2 counsellor places. The anonymous member, the minor member and the
former member each have a SUCCESS payment for it. It has a held
session with a message from each of the counsellor, the former member (stored as anonymous) and the
anonymous member. All passwords are **`password`**.

| Username | What it is |
|---|---|
| `leave_demo_creator` | Lena Creator, the group's creator. Can't leave (end or delete instead). Sees the **counsellor links** and **member links** panels in the Participants tab, each with one seeded active link. Gets the "a member left your group" notice. |
| `leave_demo_counsellor` | Dr. Leo Counsellor, the group's active counsellor. Gets the "a member left" notice; can't leave as a member. |
| `leave_demo_anonymous_member` | Ada Hidden, a current member who joined **anonymously** -- shown to others as "Anonymous", and named that way in the leave notice. Can leave. |
| `leave_demo_minor_member` | Milo Minor, 15, a current member (`was_minor_at_join` set) with guardian `leave_demo_guardian`. Leaving tells the guardian in neutral wording. |
| `leave_demo_guardian` | Gail Guardian, `leave_demo_minor_member`'s guardian. |
| `leave_demo_former_member` | Felix Former, **left three days ago** (`left_at` set). Their message in the session stays, shown as anonymous. Opening the (private) group shows only a "You left this group" box: report the group, or "ask to join again" (sends a membership request, since the group needs approval). Can't become one of its counsellors. |
| `leave_demo_invitee` | Ivy Invitee, not in the group -- use the seeded **member link** as her (`/links/{uuid}`). |
| `leave_demo_link_counsellor` | Dr. Lara Linkcounsellor, a verified counsellor not in the group -- use the seeded **counsellor link** as her. |

The two links' uuids (each works once). In the current local database (group id 16, restored
2026-11-05 after QA) they are:
- member link: `http://localhost:8000/links/61daf278-476b-40fd-b722-71f70d9f5fd7`
- counsellor link: `http://localhost:8000/links/092b478b-cb3d-43f4-b41e-16bcf95602d3`

A fresh seed makes new ones; print them with:
```bash
docker compose exec -u www-data -e HOME=/tmp php php artisan tinker --execute='
$g = App\Models\GroupTherapy::where("name", "Leave Demo Group Therapy")->first();
echo "group {$g->id}\n";
App\Models\Link::where("for_type", App\Models\GroupTherapy::class)->where("for_id", $g->id)->get()->each(fn ($l) => print("{$l->type} {$l->state} http://localhost:8000/links/{$l->uuid}\n"));'
```
To put the demo back without a full reseed (the group private and PAID, the seeded memberships and
payments, fresh links), use the seeder's own helpers from tinker:
`DatabaseSeeder::LEAVE_DEMO_PAYMENT_DATA`, `(new DatabaseSeeder)->seedLeaveDemoMemberships(...)` and
`->seedLeaveDemoLinks($group, $creator)`, after deactivating the old links.

See `documentation/features/scrum-73-leave-group-therapy.md`.

## Admin area: requests to resolve and the overview (SCRUM-387, SCRUM-49)

Log in as the super admin (`mr_robertamoah`) or as the normal admin `suspension_demo_normal_admin`
(password `password`) and open `/administrator`. `DatabaseSeeder::createAdminAreaDemoData()` adds
what nothing else seeds, so every queue type and every to-do count has something to show:

| Username | Password | What it is |
|---|---|---|
| `admin_area_verify_counsellor` | `password` | Dr. Verify Demo, unverified, with a **pending counsellor verification request** (two licences, no documents), addressed to the super admin like the app does. Shows in "Requests to resolve" and on `/administrator/counsellor-verification`. |
| `admin_area_org_owner` | `password` | Owner of **Verify Demo Organisation** (unverified), which has a **pending organisation verification request**. Any admin can see and answer it in the queue. |
| `admin_area_payout_counsellor` | `password` | Dr. Payout Failed: a **FAILED payout** whose earning is still waiting to be paid again, and a **FAILED refund** on a payment by `payout_demo_client` (with the provider's reason, listed under "Failed refunds" on `/administrator/refund-requests`). |

The rest of the queue and counts come from the demo data above: the age verification
(`age_verification_demo_user`), the dob change with no guardian, the pending refund request, the
counsellor's safeguarding exception request for `paused_minor` (super admins only), the open
reports, the suspension appeal and the billing-suspended organisation.

`DatabaseSeeder::createAdminAreaQaDemoData()` (SCRUM-387 QA) adds:

| Username | Password | What it is |
|---|---|---|
| `suspendable_demo_admin` | `password` | A **normal administrator** named in a resolved report ("suspension recommended") by `suspension_ready_counsellor`, about "Suspension Demo Therapy (administrator to suspend)". The super admin can suspend them from that report (only a super admin can't be suspended). |
| `involved_demo_super_admin` | `password` | A **second super administrator** who is also an ACTIVE counsellor (Dr. Involved DemoSuperAdmin) of "Paused Minor Demo Group", where `paused_minor` is a member. `paused_minor`'s safeguarding exception request shows to them only as one they are involved in: no name, no therapy, no buttons. `mr_robertamoah` can still answer it. |

It also adds two testimonials by `suspension_demo_client` (one in use on the home page, one not)
and the how-to "Answering a request" (page "Check Requests", two steps), for Content > Testimonials
and How-tos.

**Local database top-up (2026-11-06):** the local database predates these, so they were added with
the seeder's own code under `Model::unguarded()`, as `www-data` in tinker
(`(new DatabaseSeeder)->createAdminAreaQaDemoData($superAdmin)`), plus the failed refund's status
history row. The seeded safeguarding request for `paused_minor` had been answered during QA, so a
fresh pending one (the same as the seeder's) was added the same day.

See `documentation/features/scrum-387-admin-area.md`.

## Counsellor verification renewals (SCRUM-31)

Every verified counsellor above gets an end date 12 months from seeding
(`DatabaseSeeder::giveVerifiedCounsellorsAnEndDate()`, the same pass the backfill migration makes),
so they all show "Verified". `DatabaseSeeder::createCounsellorVerificationRenewalDemoData()` then
adds one counsellor in each renewal state, each with a short history:

| Username | Password | What it is |
|---|---|---|
| `renewal_due_counsellor` | `password` | Dr. Due Renewal: verified a year ago, **ends in 20 days**, not reminded yet (run the reminder sweep to send the 30-day reminder). In the overview's renewals count and on the Renewals list. |
| `reverifying_counsellor` | `password` | Dr. Reverifying Renewal: **ends in 10 days**, already reminded, with a **pending re-verification request** (two licences, no documents) to accept or reject. |
| `lapsed_counsellor` | `password` | Dr. Lapsed Renewal: **ended 3 days ago**. Still has an in-session therapy, "Renewal Demo Therapy (counsellor's verification ended)", with `renewal_demo_client`, which carries on; can't take on anyone new. |
| `revoked_counsellor` | `password` | Dr. Revoked Renewal: **revoked yesterday** by the super admin, with a reason. Shows "Verification ended" to the public and "Verification revoked" to themselves and admins. |
| `renewal_demo_client` | `password` | The client in the lapsed counsellor's therapy, and owner of **Renewal Demo Public Therapy (no counsellor yet)** -- public and waiting for a counsellor, to try offering help as `lapsed_counsellor` or `revoked_counsellor` (refused). |

`unverified_counsellor` (above) is the never-verified case: "Not verified yet", can't take on clients.

**Local database top-up (2026-11-06):** the migrations' backfill gave the existing local counsellors
their end dates, and the five accounts above were added with the seeder's own method in tinker as
`www-data` (the public therapy separately). Browser QA then revoked `renewal_due_counsellor`, accepted
`reverifying_counsellor`'s request (renewed to a year after its old end) and added a session "QA lapsed
session" to the lapsed counsellor's therapy; a reseed restores them.

See `documentation/features/scrum-31-counsellor-verification-renewal.md`.

## Ranking, card buttons and offers (SCRUM-113, SCRUM-114, SCRUM-389)

`DatabaseSeeder::createDiscoveryDemoData()` (after the renewal accounts):

| Username | Password | What it is |
|---|---|---|
| `discovery_demo_user` | `password` | Saved preferences: one case and one language. "Discovery Demo Therapy (matches)", "Discovery Demo Group (open)" and Dr. Discovery Helper match -- first on Home and `/public-therapies`, labelled. |
| `discovery_demo_creator` | `password` | Creates the public groups: **(open)** (Join), **(ask to join)**, **(full)**, **(ended)**, **(counsellor not verified)** (run by `lapsed_counsellor`: not taking members), **(needs a counsellor)** (Offer to help) and **(offer waiting)** -- a pending offer to help from `discovery_demo_counsellor` to accept in Requests. |
| `discovery_demo_counsellor` | `password` | Dr. Discovery Helper, verified, with the matching case and language: sees "Offer to help" on cards and pages, and has **My Dashboard** (`/counsellor/dashboard`, SCRUM-391) with "Therapies you could help" filled. |
| `discovery_demo_client` | `password` | Owns the public therapies "Discovery Demo Therapy (matches)" and "(other)", both waiting for a counsellor ("find a counsellor"). |

**Local database top-up (2026-11-07):** added with the seeder's method in tinker (unguarded, as
`www-data`). Browser QA then joined "(open)" anonymously and asked to join "(ask to join)" as
`discovery_demo_user`, sent offers from `discovery_demo_counsellor` (and the creator accepted the
"(offer waiting)" one), and sent a request from `discovery_demo_client`; a reseed restores them.

See `documentation/features/scrum-113-114-389-ranking-cards-offers.md`.
