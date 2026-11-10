# SCRUM-381: safeguarding exception for a minor paused for a missing guardian

## What it is

Since SCRUM-288, a minor with no active guardian has their therapy paused. They can read it, but
can't write in it. A **safeguarding exception** lets the **super administrator** lift that pause
for a while. It lasts at most 30 days and needs a reason. It covers the minor everywhere: every
1:1 therapy and every group they belong to.

- **Who decides:** only the super administrator grants, renews or ends an exception, and approves or
  rejects a counsellor's request for one. A super administrator who counsels the minor (in an open
  1:1 or group) can't do any of that for them, and isn't sent the "requested" notice. Other
  administrators can see the page but can't change anything.
- **Involved administrators see only that it exists:** an administrator who counsels the minor, or
  who asked for the exception as their counsellor, sees "You're involved with this client" on the
  admin page instead of the minor's name, the reasons or the therapy. The same applies in the
  requests list to a super administrator who counsels the minor (as with reports, SCRUM-362).
- **The terms:** the end must be more than an hour away and at most 30 days away (checked when it
  is granted). The reason can be up to 2000 characters.
- **Renewing:** gives a new end date and needs a new reason. The old exception is kept and marked
  "renewed", and the new one links back to it.
- **Video is not covered.** Video calls still need a guardian's video consent. Chat and sessions
  carry on. In a group (the user's decision, 2026-11-05), no minor can join video at all yet,
  creator or member, with or without an exception, because group video consent doesn't exist yet
  (SCRUM-313). The group page shows them "Video calls in a group need a parent or guardian's consent
  first." instead of a "join video" button.
- **Counsellors can ask.** The counsellor of a 1:1, or an active counsellor of a group, can ask for
  an exception for a paused client or member. The request goes to the super administrator, who
  approves it (with their own end date and reason) or rejects it.
- **Who is told, and what:**
  - **On grant:** the minor and their counsellors (1:1 and group) get a neutral notice that
    includes the end date. They are never told the reason or anything about the family. The
    minor's notice reads "Your therapy can continue for now, until …". It never says "a guardian
    again".
  - **On renewal:** the same people are told the new end date ("The date a therapy can continue
    until has changed"). A renewal can also bring the end forward, so the notice never says "for
    longer".
  - **Three days before the end:** a heads-up goes to the minor, their counsellors and the super
    administrator. The super administrator's copy links to the admin page. It is sent once per
    exception, and skipped if a guardian is back by then.
  - **When it runs out or is ended early,** with still no guardian, the therapy pauses again with
    the usual SCRUM-288 notices, and the minor is taken out of any live call.
- **Resumed notices:** "resumed" goes out only for therapies that nothing else still blocks (an
  ended therapy, the minor's own suspension, a suspended counsellor).
- **Group counsellors and pauses (changes SCRUM-288):** a group's counsellors now get the same
  paused and resumed notices as a 1:1 counsellor. They also see a banner on the group page listing
  the paused members, each named as the group shows them, so an anonymous member stays "Anonymous".
- **Audit:** exceptions are never deleted. Every administrator can see them at
  `/administrator/safeguarding-exceptions`. When the minor's closed account is anonymised, the row
  stays but its reasons are removed. The reason on any counsellor request is removed too.

## How it works (for developers)

- **Storage:** exceptions live in the `safeguarding_exceptions` table (`App\Models\SafeguardingException`).
- **Whether one is in force:** worked out from its timestamps on every request (`scopeActive`), so
  a missed scheduler run can't make one last longer. `User::isPausedForMissingGuardian()` checks
  for an exception only after the guardian check fails, so the many minors who have a guardian
  cost no extra query.
- **Actions** (`app/Actions/SafeguardingException/`):
  - `GrantSafeguardingExceptionAction` grants or renews, through `HandleGuardianRestoredAction::around()`
    (trigger `safeguarding_exception_granted`).
  - `EndSafeguardingExceptionAction` ends one early.
  - `SendSafeguardingExceptionRequestAction` sends a counsellor's request.
  - `GetPausedGroupMembersAction` lists a group's paused members.
  - Every change locks rows in one order: the minor's user row, then their exception rows, then
    any request rows.
- **Counsellors' requests:** stored as `Request` rows of type `SAFEGUARDING_EXCEPTION_REQUEST`.
  - `from` is the counsellor, `to` is null, and `for` is the Therapy or GroupTherapy.
  - `data` holds `reason`, `proposedEndsAt` and `wardId`. `wardId` is never sent to the counsellor.
  - The super administrator answers through the normal `requests.respond` endpoint. Accepting needs
    `endsAt` and `reason`, and is refused with a 422 if the client is no longer paused.
  - A direct grant closes any other pending request for that minor (status `INCONSEQUENCIAL`,
    shown as "Closed").
- **Admin page (SCRUM-384):** "Paused now" is paged 20 at a time on `?pausedPage=` (the exceptions
  stay on `?page=`). A "Requests no longer needed" list shows pending requests whose minor isn't
  paused any more (a guardian, an exception in force, turned 18, or a closed account), with the
  reason. The super administrator can reject them there. An involved administrator sees them masked,
  as on the rest of the page. The grant form posts `endsAt` (camelCase, like `requests.respond`).
- **One limit (SCRUM-384):** `SafeguardingException::MAX_DAYS` reaches `SafeguardingExceptionTermsFields`
  as a `maxDays` prop: from the admin page's props, `TherapyResource`/`GroupTherapyResource`
  (`safeguardingExceptionMaxDays`, counsellors only) and `RequestResource` (`safeguardingException.maxDays`,
  the respondent only).
- **Requests list cost (SCRUM-384):** `PreloadRequestListAction` loads each row's `from`/`to`/`for`
  and each safeguarding request's minor once per page. `tests/Feature/SafeguardingExceptionFollowupsTest.php`
  pins the query count.
- **Closed accounts in counsellors' notices (SCRUM-384):** the pause, resume and continuation notices
  to counsellors store `wardRef`, an HMAC of the minor's id with the app key (`App\Support\WardRef`),
  never the id. Anonymising the account sets `clientName` to "a closed account" in those rows.
- **Scheduler** (`routes/console.php`):
  - `end-expired-safeguarding-exceptions` runs every minute. It records each end and, if the minor
    is still paused, schedules the loss handling (trigger `safeguarding_exception_expired`).
  - `safeguarding-exception-expiry-reminders` runs hourly and sends the 3-day heads-up.

## Try it out

Log in at http://localhost:8000. Every password is **`password`** (the super administrator uses
`SUPER_PASSWORD` if set).

1. **The counsellor's view of a paused client.** Log in as `paused_minor_demo_counsellor` and open
   "Paused Minor Demo Therapy" (from Therapies).
   - The amber "This therapy is paused" banner shows "Safeguarding exception requested — waiting
     for review", because a request is already seeded.
   - Open "Paused Minor Demo Group". Its banner says a member's participation is paused and lists
     **Jordan Avery** (not anonymous), with an "Ask for a safeguarding exception" button.
   - Click it, give a reason, pick 7/14/30 days or a date, and send. The button turns into
     "Requested — waiting for review".
   - Casey (`safeguarding_exception_minor`) is in this group too, but isn't listed, because their
     exception is in force.
2. **The super administrator decides.** Log in as `mr_robertamoah` and open
   http://localhost:8000/administrator/safeguarding-exceptions (or Administrator, then "safeguarding
   exceptions").
   - "Paused now" lists Jordan Avery with the counsellor's requests. "Approve" opens an end date
     and reason form (prefilled from the request). "Grant exception" creates it.
   - The request can also be approved from the requests list (the bell or requests modal). The
     badge's "accept" opens the same form.
   - Expected result: Jordan disappears from "Paused now", and an "active" row appears under
     "Exceptions". `paused_minor` can send messages again, but joining video still asks for a
     guardian's consent.
   - Mailpit (http://localhost:8027) shows "Your therapy on TalkTherapy can continue" to Jordan
     ("…can continue for now, until <date> (UTC)") and "A paused therapy has started again" and "A
     group member can take part again" to the counsellor. None of them include the reason.
3. **Renew or end.** On an active row, "renew" asks for a new end and a new reason. The old row
   becomes "renewed" and Mailpit shows "The date a therapy can continue until has changed". "End early" asks for a
   reason. With still no guardian, the therapy pauses again: the banner is back, and Mailpit shows
   the paused notices.
4. **The 3-day heads-up.** Casey's exception ends in 2 days. Run
   `docker compose exec -u www-data php php artisan schedule:test --name=safeguarding-exception-expiry-reminders`,
   or wait for the hourly run.
   - Mailpit shows "Your therapy on TalkTherapy will be paused soon" to Casey, "A therapy will be
     paused soon" to the counsellor (the group one names Casey "Anonymous"), and "A safeguarding
     exception ends soon" to the super administrator.
   - Running it again sends nothing.
5. **A normal administrator.** Log in as `suspension_demo_normal_admin`. The page and the audit
   list show, but there are no grant, renew, end or approve buttons, and the request doesn't appear
   in their requests list.

## Deployment notes

- Two migrations: the `safeguarding_exceptions` table and the new `requests.type` enum value.
- The SCRUM-382 backfill, once after deploying:
  `docker compose exec -u www-data php php artisan guardians:announce-existing-pauses [--dry-run]`.
  Always run it as the web user, never root (in production: as the web server's user), because it
  writes the scheduler cache. If it can't queue an account, it lists it and exits with 1. Running it
  again retries just those accounts.
- `docker compose restart queue` after deploying, so the worker loads the new code.
- What to alert on: `documentation/operations/monitoring.md`.

## Test data

Seeded by `DatabaseSeeder::createSafeguardingExceptionDemoData()`. See "Safeguarding exceptions
(SCRUM-381)" in `documentation/seeded-data.md`.

- `paused_minor`: paused, with a pending request from `paused_minor_demo_counsellor` on "Paused
  Minor Demo Therapy".
- `safeguarding_exception_minor` (Casey Morgan, 15): an active exception ending in 2 days, a 1:1
  "Safeguarding Exception Demo Therapy", and an anonymous member of "Paused Minor Demo Group".
- `paused_minor_demo_counsellor`: counsellor of both 1:1s and of the group.
- `mr_robertamoah`: the super administrator.
- `suspension_demo_normal_admin`: a normal administrator, who can view only.

## Tests

`tests/Feature/SafeguardingExceptionTest.php` covers:

- the rule;
- the gates, frozen in time past the end;
- who may grant, and the limits on the terms;
- the notices and their wording;
- renewal, ending early, and the expiry and reminder sweeps;
- counsellors' requests (1:1 and group, refusals, listing, answering, masking);
- group counsellor notices;
- video staying blocked;
- the admin page;
- anonymisation;
- date-of-birth changes;
- the review follow-ups: a co-counselling super administrator can't answer a request and sees it
  masked (requests list and admin page), a late reject, accepting while an exception is in force, a
  grant between the end and the sweep, a renewal that ends sooner, the stored end in notices, the
  memo, the "requested" recipients, and group video for minors.

The SCRUM-288 lifecycle tests were updated: a counsellor who runs both the minor's 1:1 and their
group now gets a notice for each.
