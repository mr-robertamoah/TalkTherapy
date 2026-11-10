# "I need help now" -- quick help chat, part 3: after the chat (SCRUM-33, TT-4.4c)

The last of three PRs (TT-4.4a core, TT-4.4b admin queue and escalation, TT-4.4c follow-up). Decisions
are in `documentation/decision-log.md`.

## What was built

### An invitation to a regular therapy
- When a help chat has **ended**, the counsellor in it sees **"Invite to a regular therapy"** on the
  chat page. They confirm. The confirmation says the chat isn't copied, and that if the person asked
  anonymously the counsellor only learns who they are if the person shares it. The person is told
  **"{Counsellor} invites you to continue with them"** on the chat page, and by email and an in-app
  notification record.
- The person's **Start a therapy with {Counsellor}** opens the counsellor's profile with the
  individual-therapy form already open, with that counsellor already chosen. From there it's the usual
  flow: the person fills the form (name, anonymity, payment) and the counsellor gets the usual request
  to accept. Payment, a minor's guardian, a suspension and the counsellor's limits all apply as for
  any therapy. Nothing is created until the person chooses to.
- An **administrator** can send the same invitation from **Administration → Safety → Urgent help**,
  under "The last day" (**Invite to a regular therapy**). It is always with the counsellor who was in
  the chat. An administrator involved in the request (who asked, their guardian, the counsellor) can't.
- An administrator's invitation reads as **our team's suggestion** ("You could continue with …"), not as
  the counsellor inviting.
- You can only invite once per request, only after a chat that actually took place, only while the
  counsellor is verified and not suspended, and not while a report about the chat is open. Once the
  counsellor can't take clients, an invitation already sent is no longer shown.

### Keeping help chats for a year
- Every night (01:45), help chats that ended **over a year ago** (`HELP_TRANSCRIPT_RETENTION_DAYS`,
  default 365) are cleared:
  - What's deleted for good: their messages, any counsellor notes on those messages, the person's
    one-line note, and an administrator's close note.
  - What's kept: the request itself (when, who, how it ended), for the record.
  - A chat with an open report about it, its session or one of its messages waits until the report
    is resolved.
  - The chat page then says "The messages in this chat were deleted a year after it ended."
- Closing an account (its anonymisation) also clears the notes on its help requests.
- **/privacy** has a new "Urgent help" section (what's kept, who sees it, administrators' recorded
  access) and a retention line that shows the configured period.

### Smaller things
- My Dashboard's "Available" badge for urgent help now switches off by itself when the time runs out,
  without needing a reload.
- A chat whose session row is missing still ends once its time is up.

## How to try it

`docker compose up -d`, then http://localhost:8000. Password for every account: `password`.

1. **Invite (counsellor).**
   - Log in as `help_demo_user` in one browser, tap **I need help now** → **Ask for a counsellor now**.
   - In another browser (private window), log in as `help_demo_counsellor` (Dr. Efua Help) and
     **Accept**, then **End chat**.
   - On the ended chat (`/help/chat/{id}`), click **Invite to a regular therapy** → **Send the
     invitation**. It now says "They were invited…".
   - Alternatively, the seeded ended chat between them hasn't been invited yet either.
2. **Invite (person).**
   - As `help_demo_user`, open the same chat. You'll see "Dr. Efua Help invites you to continue with
     them" → **Start a therapy with Dr. Efua Help**.
   - The counsellor's profile opens with the therapy form open and the counsellor chosen. Fill it in
     and create it; the counsellor gets the usual request.
   - Mailpit (http://localhost:8027) has "An invitation to continue with a counsellor", with nothing
     from the chat in it.
3. **Invite (admin).**
   - As `help_demo_user`, ask for help. As `help_demo_counsellor_two`, accept, then end it.
   - As `suspension_demo_admin`, open **Urgent help**. Under "The last day", click **Invite to a
     regular therapy**. The row then says "Invited to a regular therapy with Dr. Yaw Help".
   - As `help_demo_user`, that chat now says "You could continue with Dr. Yaw Help" ("Our team
     suggests it").
4. **Retention.**
   - As `help_demo_user`, open `/help/chat/{id}` of the seeded chat from over a year ago: "The messages
     in this chat were deleted a year after it ended."
   - To run the nightly job by hand:
     `docker compose exec -u www-data -e HOME=/tmp php php artisan schedule:test --name=prune-help-chat-transcripts`.
5. **/privacy** → "Urgent help".

## Test data

`DatabaseSeeder::createHelpDemoData` (see `documentation/seeded-data.md`):
- `help_demo_user` has an ended chat with Dr. Efua Help that hasn't been invited yet, plus one from
  over a year ago whose messages are deleted.
- `help_demo_counsellor` and `help_demo_counsellor_two` are the counsellors.
- `suspension_demo_admin` is an administrator.

## Not in this PR (SCRUM-395)

These follow-ups are still listed on SCRUM-395:
- prank-ring caps and "report this request";
- web push;
- monitoring and a kill switch;
- an email cooldown for alerts;
- a notice for a counsellor rung directly who doesn't have the app open;
- monitoring of the job queue;
- a notification bell.

## Tests

`tests/Feature/HelpFollowUpTest.php`, plus `HelpRequestTest.php`, `HelpAdminQueueTest.php` and
`SessionsRealtimeFollowupsTest.php` (the /privacy retention props).
