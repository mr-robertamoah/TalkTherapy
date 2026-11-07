# Admin search, counsellor dashboard, and UI polish (SCRUM-390, SCRUM-391, SCRUM-393)

## What was built

### SCRUM-390: search on every admin list page

Every administrator list page has a search box at the top: it searches as you type (after a short
pause) or on Enter, and **Clear** resets the list. It keeps the page's other filters (status, type,
"renewals", ...) and its paging.

| Page | Searched by |
|---|---|
| People → Users | first/last/other names, username, email |
| People → Counsellors | counsellor name, and the person's names/username |
| Counsellor verification (requests and renewals) | the counsellor's name and person |
| Requests | who sent it (person, counsellor or organisation) |
| Reports | the reporter, or the therapy/group it's about |
| Suspensions | the suspended person |
| Payouts (history) | payout reference or counsellor |
| Refund requests (pending and failed) | who paid, or the transaction reference |
| Organisation billing | organisation name |
| Safeguarding exceptions | the minor |
| Content-access log | the administrator who read it |

- At least 2 characters (one character searches nothing); more than 100 is refused.
- `%` and `_` are matched literally.
- A row the viewing administrator is **involved in** (their own report, a ward's suspension, a minor
  they counsel, ...) never matches a search, though it still shows, as a summary, in the unsearched
  list -- a search can't be used to confirm who is involved in what. If that would mean checking more
  than 200 matching rows, the page asks for a more specific search.

### SCRUM-391: a counsellor's dashboard

Counsellors have **My Dashboard** (user menu, desktop and mobile; `/counsellor/dashboard`) with:

1. **Needs your action** -- requests waiting on them (therapy/group invitations, discussions, session
   time proposals, and membership requests for groups they created), answerable in place.
2. **Upcoming sessions** -- the next 7 days, linking to the therapy or group; "Calendar" opens the full
   calendar.
3. **Verification** -- the current state and when it ends, with renewal.
4. **Earnings** -- available to withdraw, the last payout, and whether payouts are on hold or need a
   payout destination.
5. **Your current therapies and groups** -- only active ones, with Open (and "Paused" where their access
   is paused).
6. **Therapies you could help** -- public therapies and groups looking for a counsellor, closest to
   their profile first, with "Offer to help". Shown only while their verification is current.

Someone without a counsellor profile is sent home with an alert.

### SCRUM-393: UI polish (from the TODO list)

- **Preferences page:** cases, languages and religions each have the search box first, then the
  matching items as chips you tap to choose (a tick when chosen, up to 20), **Load more** for the next
  page, **Can't find it? Add a ...** (a small form; the new item is chosen for you), and "Your ..."
  underneath with remove buttons. "Set preferences" is enabled only when something changed, and again
  after each save. A note explains the match mark.
- **Therapy and group cards:** simpler. The kind is shown by colour and icon -- a blue edge and a
  person for an individual therapy, an orange edge and a group for a group therapy -- instead of a
  badge. "Matches your preferences" is now a small green mark that explains itself on hover or
  keyboard focus (same on starred counsellor cards). One line of facts (counsellor or counts, sessions,
  private/anonymous), a two-line description, and the card's button.
- **Find a counsellor** (a therapy's participants tab): counsellor cards are compact -- therapies,
  profession, cases, languages and religions as short lines of small text (three names, then "+N
  more"), with a **Select** button (double-clicking still works).

## How to try it

`docker compose up -d`, then http://localhost:8000. Password for all accounts below: `password`.

### Admin search
1. Log in as `suspension_demo_normal_admin` (or `suspension_demo_admin`, super).
2. Open any administrator page above (e.g. `/administrator/users`), type part of a name (e.g. `demo`)
   in the search box. The list narrows; **Clear** brings it back. On Requests, change the status filter
   while searching -- both apply.
3. Type one character: the list doesn't change. Paste 101+ characters: refused.

### Counsellor dashboard
1. Log in as `discovery_demo_counsellor`, open the user menu → **My Dashboard**.
2. See the six blocks; "Therapies you could help" lists the seeded public therapies/groups needing a
   counsellor, with "Offer to help".
3. Log in as `discovery_demo_user` and open `/counsellor/dashboard`: you're sent home with an alert.

### UI polish
1. As `discovery_demo_user`, open **Preferences**: search "grief" under Cases, tap a chip (tick), see
   "Set preferences" enable; save, and it disables again. Tap the chip again and save to restore.
2. Open `/public-therapies`: blue-edged individual and orange-edged group cards; hover or Tab to the
   green mark to read "Matches your preferences".
3. As `discovery_demo_client`, open "Discovery Demo Therapy (other)" → participants → **find a
   counsellor**, search "dr": compact cards with **Select**.

## Test data

No new seed data -- existing accounts cover it (see `documentation/seeded-data.md`):
`suspension_demo_admin` / `suspension_demo_normal_admin` (admin search), `discovery_demo_counsellor`
(dashboard), `discovery_demo_user` (preferences, cards), `discovery_demo_client` (find a counsellor).

## Tests

`tests/Feature/AdminSearchTest.php`, `tests/Feature/CounsellorDashboardTest.php`.
