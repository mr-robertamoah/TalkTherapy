# "I need help now" -- part 2: the admin queue and alerts (SCRUM-33, TT-4.4b)

The second of three PRs (TT-4.4a core -- merged; TT-4.4b this; TT-4.4c follow-up and retention).
See `documentation/features/scrum-33a-help-chat-core.md` for the chat itself.

## What was built

### Administration → Safety → Urgent help
- A live queue for every administrator: **Waiting for a counsellor**, **In a chat**, and **The last
  day** (ended, nobody answered, stopped waiting, closed). It updates as things happen -- no reload --
  and so does the sidebar's **Urgent help** badge ("1 waiting") on every admin page.
- Each waiting row shows the short summary a counsellor sees (first name or "Anonymous", adult or
  under 18, country, the note), how long they've waited, whether the account is suspended, whether
  administrators have been alerted, and who was rung directly.
- **Ring on call again** -- rings every counsellor on call again ("Rang 2 counsellors on call", or
  "No counsellor is on call right now").
- **Ring a counsellor** -- pick one (searchable; those on call first). They're rung straight away and
  can accept **even if they aren't on call** -- if they can take it (a current verification, not
  suspended, not the person's guardian, not in another help chat). They still choose to accept.
- **Show who asked** -- the person's name, username and email, even if they asked anonymously (the
  user's decision); every look is recorded in the read log ("who asked for urgent help").
- **Close** -- with an optional note, when the person is being helped another way. They're told
  "Our team closed your request…", with the emergency number, and can ask again.
- An administrator who is **involved** -- they asked, they're the person's guardian, or they're the
  counsellor in it or rung for it -- sees only that a request exists, and can't act on it or look.
- Administrators coordinate; they never chat or read a chat from here.

### Alerts
- Someone still waiting after **60 seconds** alerts every administrator who isn't involved (a
  notification and an email -- "Someone needs urgent help" -- with no name and no note); still waiting
  after **3 minutes**, again. A request nobody answers still closes after 10 minutes.
- The notification is stored, but the app has no notification bell yet (follow-up, SCRUM-395): the
  email and the sidebar badge are what an administrator sees.
- On the help chat page itself the floating "I need help now" button is hidden (the chat's own
  "Emergency numbers" button opens the same dialog).

## How to try it

`docker compose up -d`, then http://localhost:8000. Password for every account: `password`.

1. As `suspension_demo_normal_admin`, open **Administration → Safety → Urgent help**.
2. In another (private) window, as `discovery_demo_user`, tap **I need help now** and ask. The request
   appears in the queue straight away, and the badge says "1 waiting".
3. **Ring a counsellor** → search "Yaw" → **Ring**. In a third window as `help_demo_counsellor_two`
   (switch "Urgent help" off on My Dashboard first, to see it works when not on call), the ring
   appears -- **Accept**. The queue moves it to **In a chat**.
4. Ask again (as `discovery_demo_client`), and wait over a minute with nobody on call: administrators
   get "Someone needs urgent help" (email in Mailpit at http://localhost:8027; the sidebar badge), and the row says
   "Administrators alerted". **Close** it with a note: the person is told.
5. **Show who asked**, then as `suspension_demo_admin` open **Read log**: the look is there.

## Test data

No new seed data -- `help_demo_*` and `discovery_demo_*` accounts (see `documentation/seeded-data.md`).

## Tests

`tests/Feature/HelpAdminQueueTest.php` (and `HelpRequestTest.php`).
