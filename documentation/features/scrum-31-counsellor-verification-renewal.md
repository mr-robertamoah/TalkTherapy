# Counsellor verification: badge, renewal and audit trail (SCRUM-30, SCRUM-31, SCRUM-388)

## What was built

### SCRUM-30: a verified badge, or a warning, wherever a counsellor is shown

- A badge on the counsellor profile (`/counsellor/{id}`), counsellor cards (`CounsellorComponent`),
  starred and home counsellor cards (`StarredCounsellorComponent`), the client's counsellor picker
  and the admin counsellor pop-up. It reads **Verified**, **Not verified yet** or **Verification
  ended**. Everyone sees it, signed out too.
- The public only ever gets the state. A revoked verification reads as "Verification ended" to
  anyone but the counsellor and administrators. No licence data is sent; the profile no longer sends
  `hasNationalIdentification` (it ran a query on every view and nothing used it).
- The counsellor sees **Your verification** on their own profile: until when, "Re-verify now" once
  the renewal window opens (or it has ended or been revoked), "being reviewed" while a request is
  waiting. `/counsellor/{id}?verify=1` (the reminder emails' link) opens the verify form.
- The admin counsellor pop-up used to say "Verified Counsellor" for everyone. It now shows the real
  state.

All of it comes from one server rule: `App\Support\CounsellorVerification::projection()` over
`Counsellor::verificationState()`, which reads columns only (no query per card).

### SCRUM-31: verification ends, renews, can be revoked, and is recorded

- **It lasts 12 months** (`COUNSELLOR_VERIFICATION_VALIDITY_MONTHS`) from the day an administrator
  accepts it. Renewing early extends from the current end date, so nothing is lost. Counsellors
  already verified when this shipped got 12 months from the day the migration ran.
- **Re-verifying** uses the same verification form and admin review as the first time (a national ID
  file or number, plus the licence). A counsellor may ask from 30 days before the end
  (the largest of `COUNSELLOR_VERIFICATION_REMINDER_DAYS`), or any time once it has ended or been
  revoked. Asking a year early is refused.
- **Reminders** go 30 and 7 days before the end, once each (a daily sweep at 05:00), unless the
  counsellor has already asked to re-verify:
  - the counsellor, in the app and by email, with a link to re-verify;
  - the administrator who last accepted their verification (recorded since SCRUM-387), if still an
    administrator;
  - every administrator, through the overview's "Counsellor verifications ending soon, not yet
    renewed" count and the **Renewals** list.
- **When it ends** (a daily sweep at 05:15 records it and tells the counsellor; the block itself
  applies the moment the date passes), and **when it is revoked**, the counsellor counts as not
  verified for **new work only**: taking on a new client (offering or accepting to assist a
  therapy, using a therapy's counsellor link, being invited by a client), starting or joining a
  group as its counsellor, posting, a first payout destination, and a new organisation link. Their
  current therapies, sessions, group places and earnings carry on, and they can still change where
  they're paid (unless revoked).
- **Never-verified counsellors** are treated the same (the user's decision): listed, with the
  warning, and they can't take on clients until verified.
- **Discussions and groups** (the user's decisions after the security review): a counsellor who
  isn't currently verified can't join or start a discussion, and a group whose counsellors are none
  of them currently verified admits no new members until one is (a waiting membership request stays
  waiting). Discussions they're already in, and current members, carry on.
- **Revoking:** any administrator except the counsellor themselves (a super administrator's only by
  another super administrator), also after it has ended, from Admin > People >
  Counsellor verification > Renewals or the admin counsellor pop-up. A reason (10–1000 characters)
  is required and is sent to the counsellor. A verification request of theirs still waiting is
  closed, so they ask again.
- **The history** (`counsellor_verification_events`): verified, re-verified, rejected, reminder
  sent, ended, revoked, and a baseline row for counsellors verified before this shipped. Each row
  has who (or "automatic"), when, the request, the licences by id (never numbers or documents), the
  reason and the end date. Append-only (model and database triggers), kept permanently, admins
  only. The Privacy page says so.

### SCRUM-388: follow-ups from the admin-area reviews

1. A counsellor-created group's creator now finds a membership request addressed to their
   counsellor profile (`GroupTherapy::pendingMembershipRequestFor`).
2. On "Requests to resolve" and Counsellor verification, an answer refused because someone already
   answered it (a 422) reloads the list and the sidebar badges, so the row stops showing Pending.
3. Counsellor verification and safeguarding rows no longer carry the counsellor's date of birth,
   gender or country.
4. `/api/admin/reports/*` is now behind the `admin` middleware too (a non-admin gets a JSON 403).
5. Deleting a counsellor and anonymising a closed account clear the admin to-do counts (both write
   admin queue requests without the model hook). The other bulk "made moot" updates only touch
   therapy, group and membership requests.

## How to try it

Test data: see `documentation/seeded-data.md`, "Counsellor verification renewals (SCRUM-31)". All
passwords are `password`. For admin pages use `suspension_demo_admin` (super admin) or
`suspension_demo_normal_admin`.

1. **Badge, signed out:** open the home page and a counsellor profile. A verified counsellor shows
   "Verified"; `unverified_counsellor` shows "Not verified yet"; `lapsed_counsellor` and
   `revoked_counsellor` show "Verification ended".
2. **Owner card:** log in as `renewal_due_counsellor` and open your counsellor profile. "Your
   verification" says it ends in about 20 days and offers "Re-verify now". As `revoked_counsellor`
   it says an administrator revoked it. As `reverifying_counsellor` it says your re-verification is
   being reviewed.
3. **Reminders:** run the sweep:
   `docker compose exec -u www-data -e HOME=/tmp php php artisan tinker --execute='echo App\Actions\CounsellorVerification\SendCounsellorVerificationRemindersAction::new()->execute();'`.
   `renewal_due_counsellor` gets the 30-day reminder (see Mailpit, http://localhost:8027). Running
   it again sends nothing.
4. **Renewals list:** as an admin open `/administrator/counsellor-verification?view=renewals`. Open
   a counsellor's **History**; **Revoke** one with a reason. The state turns to "Verification
   revoked" and the counsellor gets an email with the reason.
5. **Accept a renewal:** in the request list, open `reverifying_counsellor`'s request and accept it.
   Their end date moves 12 months on from the old one and History shows "Re-verified".
6. **New work blocked, current work carries on:** as `lapsed_counsellor`, open "Renewal Demo Therapy"
   (it still works, with `renewal_demo_client`), then try to offer help on a public therapy: you are
   told your verification isn't current. Use "Renewal Demo Public Therapy (no counsellor yet)".
7. **SCRUM-388 refusal:** open `/administrator/requests` in two windows as two admins, answer the
   same request in both. The second shows why it was refused and the list refreshes.

A successful result: badges match the states above for every viewer, reminders go once, revoking
and accepting are recorded in History, and the overview's renewals count matches the Renewals list's
counsellors still waiting to ask.

## Deployment notes

- Three migrations: the two new `counsellors` columns, the append-only events table (MySQL with
  binlog needs `log_bin_trust_function_creators=1`, as for `admin_content_accesses`), and the
  backfill (12 months from when it runs, so nobody lapses on release day).
- Two new daily schedules (`counsellor-verification-reminders`, `record-ended-counsellor-verifications`).
- Optional env: `COUNSELLOR_VERIFICATION_VALIDITY_MONTHS` (12), `COUNSELLOR_VERIFICATION_REMINDER_DAYS` (`30,7`).
- `Counsellor::isVerified()` now means **currently** verified; use `wasEverVerified()` for history.
