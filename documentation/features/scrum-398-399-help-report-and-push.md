# SCRUM-398 + SCRUM-399: reporting an urgent help request, and web push for rings

Two stories in one PR, each in its own commits. Reporting is SCRUM-398; web push is SCRUM-399, behind
`HELP_PUSH_ENABLED`, which is off by default.

## SCRUM-398: a counsellor reports an urgent help request

- **Who can report:**
  - The counsellor who **answered** a request can report it, during the chat or after it, until its messages
    are deleted.
  - A counsellor it is **ringing** can report it from the ring dialog, with **Report this request**.
  - Each counsellor can report a request once.
- **It changes nothing for the person:**
  - the request isn't cancelled, other counsellors are still rung, and the chat goes on;
  - they aren't told, then or when the report is closed.
- **Administrators** review it in the usual **Reports** queue, with these differences:
  - **Who asked** shows only as "Anonymous" or their first name, never a name, username or id, in the list or on the page.
    The queue shows no more than that; the note and the rest are on the report's own page.
  - **The page shows** the note, when they asked, and when it was answered and ended. **Show who asked** reveals the name
    and username, and **every reveal is recorded** in the read log (`help_request_identity`).
  - **When it can be revealed:** while the report is open or under review, by any reviewing admin. Once resolved with
    "suspension recommended", only a super admin, and only until a suspension is applied.
  - **Involved admins:** an admin involved in the request sees only the usual "you're part of this report" line. That
    covers the person, their guardian, the counsellor who answered, and a counsellor-admin while it is ringing them.
    Who was rung isn't kept, so once it has stopped ringing, a counsellor-admin who was rung (and didn't answer or report
    it) can review it like any other admin.
  - **Suspending** someone over it needs the admin to have revealed who asked first.
  - **The suspension** shows who asked only to an admin who has revealed it. Everyone else sees the label, on the report
    and in **Suspensions**, and a name search there never matches it.
  - **Reading the chat** is possible through the usual logged confidential-read flow, if the request was answered.
- **Retention:** while a report is open or under review, the request's note and chat are kept, and no invitation to a
  regular therapy can be sent.
- **Fixed alongside (a leak):**
  - **The leak:** reporting a help chat's session or therapy through the general report form used to show admins who
    asked, with no read log. It is now refused there.
  - **Guessing loophole:** a report on an anonymous therapy's session can no longer confirm a guessed client id.
  - **Report emails** skip involved admins.

## SCRUM-399: web push to on-call counsellors

- **Turning it on:** on **My Dashboard → Urgent help → Alerts on this device**, a counsellor chooses **Alert this device**,
  and the browser then asks permission. Other devices are listed and can be removed.
- **iPhone and iPad:** push works only when TalkTherapy has been added to the Home Screen and opened from there. The card
  says so.
- **When someone asks for help,** every counsellor the ring reaches gets a notification on their alerted devices,
  including one an administrator rings directly. It reads **"Someone needs urgent help. Open to see if you can answer."**
  and contains nothing about the person.
- **Tapping it** opens the app, which shows the ring if they're still waiting.
- **When the request is answered, cancelled or closes,** the notification is replaced and cleared.
- **No push** goes out when the ring cap skips a request, or when push is off or has no keys.
- **Pushes expire after 2 minutes** (`HELP_PUSH_TTL`) and are sent from the `urgent` queue.
- **Devices are removed:**
  - **Sign-out:** signing out from any page first removes and unsubscribes this browser (by its endpoint). The server
    also removes the device turned on in the session that's ending, as a backstop.
  - **Suspension and account closure:** all their devices are removed.
  - **Gone:** devices the push service says no longer exist, or whose keys can't be used, are removed.
- **Only real push services:** a device's address must be https, at one of the push services in
  `HELP_PUSH_ENDPOINT_HOSTS` (Google, Mozilla, Apple, Microsoft by default). Anything else is refused, and never posted
  to. Redirects aren't followed, and each send times out after 5 seconds.
- **What the push services see:** an encrypted payload, and an opaque reference per request (not its id).
- **The service worker** (`/sw.js`) handles notifications only. It caches nothing.

## How to try it

### Reporting

1. Sign in as the super admin `mr_robertamoah`. Open **Administrator → Reports**.
2. Find the seeded report on an **urgent help request**, made by Dr. Yaw Help. The person shows as **Anonymous**.
3. Open it. You see the note ("lol just testing this") and when it was asked and when it ended.
4. Press **Show who asked**. Ama (`help_demo_prank`) appears. Check **Administrator → Read log**: there is a
   "who asked for urgent help" entry.
5. As a counsellor: sign in as `help_demo_counsellor` (Dr. Efua Help) and open the earlier ended help chat with Akosua.
   It's in the urgent help section of their dashboard, or reached directly via `/help/chat/<id>`. Press **Report**, choose
   a reason and send.
6. Ring flow:
   - sign in as `help_demo_user` in one browser and tap **I need help now**;
   - in another browser, as `help_demo_counsellor_two`, choose **Report this request** in the ring dialog;
   - Dr. Efua Help is still rung and can accept.

### Push

1. Locally `.env.docker` has `HELP_PUSH_ENABLED=true` and generated keys. Otherwise run
   `docker compose exec php php artisan push:vapid-keys`, put the output into `.env.docker`, and recreate `php` and `queue`.
2. As `help_demo_counsellor`, open **My Dashboard**. Under **Urgent help** press **Alert this device** and allow
   notifications.
3. Close the tab, keeping Chrome running. In another browser, as `help_demo_user`, tap **I need help now**. A
   notification appears. Accepting the request, as Dr. Yaw Help in a third session, clears it.

Delivery depends on the browser's push service, so it needs internet access. Headless tests use a fake sender.

## Test data

From `DatabaseSeeder` (`createHelpDemoData` and `createHelpReportDemoData`). All passwords are `password`.

- `help_demo_prank`: Ama. Asked anonymously, nobody answered, and Dr. Yaw Help reported it as a prank, so there is
  an open report.
- `help_demo_counsellor` and `help_demo_counsellor_two`: on call. Dr. Efua Help answered Akosua's earlier chat and can
  report it.
- `help_demo_user`: asks for help.

## Deployment notes

- **Migrations:** `push_subscriptions`.
- **New package:** `minishlink/web-push` (v11). It needs no new PHP extension (it uses `bcmath`).
- **docker/queue/supervisord.conf:** the worker now runs `--queue=urgent,default`. It is bind-mounted, so restart the
  `queue` container.
- **docker/nginx/default.conf:** `/sw.js` (no-cache) and `/manifest.webmanifest`. It is bind-mounted, so restart `web`.
- **Env:** `HELP_PUSH_ENABLED` (off), `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` and `HELP_PUSH_TTL`.
  See `documentation/operations/production-checklist.md`.
