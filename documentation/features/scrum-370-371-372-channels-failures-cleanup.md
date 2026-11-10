# Shared channels, one failure response, realtime leftovers (SCRUM-370, 371, 372, 145, 151)

One PR. The user asked for the next group, to include the three follow-ups from the SCRUM-368/369
reviews. SCRUM-145 and SCRUM-151 were added as small fixes in the same areas. The reasoning is in
`documentation/decision-log.md` ("SCRUM-370 + 371 + 372 + 145 + 151").

## What was built

### SCRUM-370: chat channels survive other components closing
A session's channel (`sessions.{id}`) has several listeners at once: the chat, its video call, and
every session card. A discussion's channel (`discussions.{id}`) is joined by its chat page and every
discussion modal on a therapy page. Each listener used to leave the channel when it closed, which
cut everyone else off. For example, closing a modal could stop messages arriving in the open chat.

Both channels now go through `resources/js/Composables/sharedChannel.js`, like the counsellor
channel. Each user removes only its own listeners, and the channel is left when the last one closes.
The module now also covers:
- presence channels ("who's online", including for a modal opened after the channel was joined);
- typing whispers.

### SCRUM-371: one failure response
The 23 controllers that each had their own copy of `returnFailure()` now share one, on the base
`Controller`. Who gets what:
- **JSON:** scripts, i.e. axios, fetch, or a request with no Accept header.
- **The red alert:** Inertia visits and browser navigations. Before, a failed browser navigation
  showed a raw JSON page, and a failed Inertia form on the four always-JSON controllers (for example,
  the organisation edit form) got JSON the page couldn't show.

A failed page visit (GET) now shows its alert on the page you're sent back to. Before, only forms
displayed it, so a refused page visit redirected silently.

Unexpected failures are now logged. Before, they were only turned into a generic message.

### SCRUM-372: realtime leftovers
- **"X has accepted your invitation to join the discussion"** now reaches the creator. It never did
  (a missing dot in the listener).
  - It used to be addressed by the creator's *user* id on the counsellor channels. That meant
    whichever counsellor had that id, with the discussion's name.
  - The text now names the counsellor.
- **Discussion times are dates,** like session times. They reach the browser in the same UTC format,
  so non-UTC viewers now see them correctly, which will look like a shift.
- **Notifications are built as their recipient.** Session, discussion and guardianship
  notifications are built for the person they are sent to, never for whoever triggered them. Under a
  synchronous queue, the acting user's view (a group's payment status, or someone's date of birth)
  used to go to everyone notified.
- **Strangers no longer join the therapy's presence channel.** Before, someone viewing a public
  therapy got a 403 in the console on every visit.
- **Dropdown above alerts:** an open dropdown now sits above a flash alert.
- **Countdown rounding:** "start" is no longer enabled up to a minute early.
- **"Starts soon" reminders go out once.**
  - Before: each participant got the session reminder (and its email) up to six times, and the
    discussion reminder up to 30 times.
  - The discussion's creator now gets the discussion reminder and the "failed" notice too.
- **The discussion "online" list** removes a counsellor who goes offline. It used to put them back
  and drop someone else.
- **Discussion edits** compare times as dates again.
- **"Already online"** in a discussion no longer counts you.
- **The create-discussion form** shows a refusal (such as a time clash) as an alert, instead of
  locking up.

### SCRUM-145: the loading strip
The "saving..." strip at the bottom of the screen no longer takes clicks while it's shown. It used to
cover a full-width band along the bottom, including a modal's buttons.

### SCRUM-151: counter-offers
Countering a compensation proposal whose other side is gone (a deleted counsellor, or an
organisation with no admins) now records the counter-offer and logs that nobody could be notified.
Before, it failed.

### Tests
- **No test may make a real HTTP call** (`Http::preventStrayRequests()` in `tests/Pest.php`).
  `AdminRefundRequestControllerTest` was intermittently failing. Approving a refund runs
  `ProcessRefundJob` inline under the test queue, which called Paystack over the network.

## How to try it

### Test data
See `documentation/seeded-data.md`. Demo accounts use `password`. The super admin is
`mr_robertamoah` (`SUPER_PASSWORD` in `.env.docker`). No new seed data was needed.

### 1. Chat keeps working (two browsers)
1. Sign in as `refund_demo_client` and `refund_demo_counsellor` in two browsers. Open "Refund Demo
   Therapy (Per Session)" with a session in progress. Reseed, or move a session into its window with
   tinker.
2. On one side, open and close the session actions modal, or a discussion card's details.
3. Send messages both ways. They arrive live, and the typing indicator still shows.

### 2. Failure responses
- While signed in, open `/links/00000000-0000-0000-0000-000000000000`. You land on home with "The
  link was not found." Before, a non-422 failure there showed raw JSON.
- Submit the organisation edit form for an organisation you don't administer. A red alert appears,
  not Inertia's error box.

### 3. Discussions
- As a counsellor, create a discussion on a therapy and invite another counsellor. When they accept,
  you get the alert "… has accepted your invitation to join the discussion …".
- With the discussion's chat page open in one tab and its modal in another, close the modal. The
  chat keeps receiving messages.

### 4. Reminders
`docker compose exec php php artisan tinker`, then create a session starting in 20 minutes and run
`App\Services\AppService::new()->notifyParticipantsOfStartingSessions()` twice. Mailpit
(http://localhost:8027) shows one reminder per participant, not two.

## Deployment notes
- No migrations or env vars.
- Rebuild the frontend. Restart the queue so the scheduler loads the new sweeps
  (`docker compose restart queue`; `deploy.sh` runs `queue:restart`).
- **Expect one repeated "starts soon" reminder or "started" announcement** for anything due or active
  at deploy time. The once-only keys are new (`scheduler-once:*`), and the deploy's `cache:clear`
  wipes them. That is tracked in SCRUM-373.
- **Discussion times show shifted for non-UTC viewers.** They are now correct, so this is not a
  regression.
- **Discussion creators get more email:** "due" and "failed" notices they never got before.

## Follow-ups
- **SCRUM-373 (ops):**
  - restart policies for the vite, web and queue containers (the scheduler silently stopped for
    about 7.5 hours after a host suspend);
  - a heartbeat for the scheduler;
  - the deploy's `cache:clear`;
  - the seed marker.
- **SCRUM-374 (architecture):**
  - render `AppException` centrally;
  - one JSON-or-redirect rule for success responses too;
  - move the scheduler sweeps out of `AppService`;
  - the therapy presence channel onto `sharedChannel.js`;
  - small purpose-built notification payloads.
- **SCRUM-375 (bugs):**
  - the countdown is off by the viewer's UTC offset;
  - a discussion's change of therapy is never saved.
