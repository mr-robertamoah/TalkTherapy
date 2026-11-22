# Counsellor recognition (SCRUM-464)

Each month a super administrator picks up to 5 "Counsellors of the month" from the counsellors who earned the most
stars under the new star rules. A recognised counsellor is asked whether they agree to be featured. Built:

- the stars (SCRUM-470, SCRUM-471);
- the recognition backend (SCRUM-473);
- the admin page (SCRUM-474);
- the per-counsellor fee waiver backend (SCRUM-475);
- the public showcase, the profile badge and the counsellor's consent controls (SCRUM-476, below).

## The admin page: `/administrator/recognition`

In the admin sidebar under **Money**, next to Payouts, as **Counsellor recognition**.

- **Every administrator** can open it and see everything on it: the suggestions, the decided rows, the audit and the
  daily star limits.
- **Only a super administrator** sees the controls: approve, skip, add a counsellor and save the limits. The server
  enforces the same rule. An ordinary admin who posts to any of those routes directly gets a **403**, and so does a
  call to the counsellor search.

### Month

The month switch offers **last month** (the default) and **this month** (UTC). Those are the only two months a
recognition can be decided for; nothing further back, so a recognition can't be back-dated. A URL with any other
month (`?month=2026-01`) returns you to the default month with the message "Choose this month or last month (YYYY-MM)."

At the top: **"N of 5 approved for <Month Year>"**. An amber note shows how many open reports aren't shown against
anyone. That number includes:

- reports that don't name exactly one person, which count against nobody until an admin records who they're about;
- reports about an urgent help request. These still keep the person who asked out of the suggestions, but the page
  never marks who that is, for any admin. That stays private until someone reveals it from the report, which is
  logged.

### Suggestions

Counsellors are ranked by the new-rule stars they earned in the month (legacy stars never count). Only counsellors
who could be recognised **now** are included: currently verified, not suspended, not deleted, and not the reported
party of an open or under-review report. The order is:

1. most stars;
2. then the earliest first star;
3. then the lowest id.

Rows tied on both stars and first star share a rank. The suggestions are the top 5 plus everyone tied with 5th place;
those tied rows carry a **"tied at cutoff"** pill.

Each row shows:

- the rank;
- the counsellor's name, linking to their profile;
- their stars, and the date of their first star (GMT);
- alerts, if any: *deleted*, *suspended*, *open report*, *verification lapsed/revoked/unverified*;
- the decision (*not decided*, *approved*, *skipped*) and, once approved, the consent state (*consent pending*,
  *consent given*, *consent declined*, *consent withdrawn*);
- an *added manually* pill for a counsellor who was added rather than suggested.

There is no fee-waiver column yet. It comes in a follow-up once SCRUM-475 (PR #377) and this page have both merged.

Star counts and ranks are shown to every administrator, except on their own row. An administrator who is also a
counsellor sees their own row without stars, first star, rank or report flag, and the audit doesn't show them who
acted on their own recognition. Counsellors never see star counts or ranks.

### Approve, skip, add (super admin)

- **Approve** / **Skip** on a row, then **confirm approve** / **confirm skip** (two clicks, as on Payouts). Approving
  sends the counsellor a featuring-consent request (on their dashboard, under "Needs your action") and a notification.
  Skipping an approval closes a consent request that hasn't been answered yet.
- **Add a counsellor**: type at least 2 characters of a name or username. The search returns at most 10
  counsellors. It only finds counsellors who could be recognised now and who aren't already suggested or decided for
  the selected month. It never finds your own counsellor profile. Click **add**, then **confirm add**.
- **"Also decided for <Month>"** lists rows decided this month that are no longer suggested: someone added by hand,
  or someone approved and suspended since. An approved row there can be skipped. A skipped row with no alerts can be
  approved again, which goes through "add".
- There are no controls on your own counsellor row; nobody decides their own recognition.
- The backend's refusals appear as an error alert. They include:
  - the month already has 5 approved;
  - the counsellor isn't eligible now;
  - "You can't decide your own recognition.";
  - the counsellor is (or isn't) among the suggestions, so use approve (or add) instead;
  - a month that isn't this one or last one.

### Consent

The counsellor answers the request on their dashboard (accept or decline). Afterwards they can withdraw their
consent or give it again (`POST /counsellor/recognitions/{id}/consent`). Each change shows on the row's consent pill
and in the audit. A declined or withdrawn recognition is never shown to anyone but the counsellor (see "Who sees a
recognition" below).

### What happened (audit)

Every decision and consent change on the month's recognitions, most recent first. Each entry shows when (GMT), which
counsellor, what happened (for example "skipped (was approved)" or "agreed to be featured"), and who did it. The
actor's name is shown to administrators only, and never on the viewer's own recognition. The consent request itself always comes from "TalkTherapy".

### Daily star limits

The most stars a counsellor can earn in a UTC day:

- from held **sessions**;
- from **help chats**;
- from held **discussions**;
- from **posts**;
- **per client** (session and help-chat stars from one client; 0 stops every session and help-chat star that has a
  client);
- **per group** (group-session stars from one group).

A super administrator edits all six at once and clicks **save limits**. Each limit must be a whole number:

- from 0 to 100;
- from 0 to 10 for the per-client and per-group limits.

0 stops that source from earning stars. A save changes all six or none. Every change is logged (`Daily star caps
changed.`), with who made it and the old and new values. The new values apply to the next star awarded. Ordinary admins see
the values read-only. Until a limit is saved, it shows the defaults from `config/recognition.php` (8, 5, 2, 3, 1, 1).

## The showcase (SCRUM-476)

### Who sees a recognition

One rule, `GetVisibleRecognitionsAction`, decides it for Home, the profile badge and nothing else:

- only **approved** recognitions;
- a **guest** sees those the counsellor **accepted**;
- a **signed-in** user also sees those still **pending** an answer;
- the **counsellor** also sees their own **declined** and **withdrawn** ones;
- **nobody** sees any while the counsellor is suspended, has an open or under-review report of any kind (an urgent
  help request's report included), has a lapsed or revoked verification, or has deleted their profile or account. It
  comes back as soon as that clears. Nothing on the page says why a recognition isn't shown, or what kind of report
  there is.

No star counts or ranks are shown anywhere public, to anyone, and neither is anything about consent or fee waivers.

### Home: "Counsellors of the month"

The first section on Home (`/`), for guests and signed-in users alike, replacing "Starred Counsellors (previous
month)". It shows the **latest month that has an approved recognition**, for example "October 2026", and only that
month. Each card shows:

- the photo, name and profession;
- the badge **"Counsellor of the month, <Month Year>"**;
- up to 3 of the counsellor's cases;
- a **View profile** link.

The cards are in approval order, never by stars. When nothing is visible, it reads "no counsellors of the month to
show yet". This guest Home section is the public showcase; `/about` is unchanged.

### Home: "Leading Counsellors (current month)"

Signed-in users only; a guest doesn't get the section, or its data. The 5 counsellors with the most **new-rule** stars
so far this UTC month, among those who could be recognised now (verified, not suspended or deleted, no open report).
Legacy stars never count. Ranked like the admin suggestions: most stars, then the earliest first star, then the lowest
id. The cards show the position but no counts.

The "Counsellors" row and `GET /api/counsellors/random` no longer send star counts, and no longer load every star row
of every counsellor on the page.

### The profile

- **The badge:** under the name on `/counsellor/{id}`, one **"Counsellor of the month, <Month Year>"** badge per
  recognised month this viewer may see, newest first.
- **The star numbers are gone** for everyone, the counsellor included (the "M / O stars" box). Star counts are kept
  for administrators on the recognition page.
- **The counsellor's own "Counsellor of the month" card** (theirs only, and only once they have an approved
  recognition) lists every approved month with where its consent stands, and the controls:
  - pending: **Agree to be featured** / **Don't feature me** (the consent request on their dashboard, answered here
    through `requests.respond`);
  - accepted: **Stop featuring me** (withdraw);
  - declined or withdrawn: **Feature me** (opt back in).

  It doesn't say whether a recognition is shown to others right now, only that it is shown "while your profile is
  verified and in good standing".

### What we hold about you

`/profile/your-data`, "As a counsellor", lists each approved recognition's month and consent ("you agreed to be
featured", "you chose not to be featured", ...). A month an administrator skipped them isn't listed.

## Routes

| Route | Who | What |
|---|---|---|
| `GET /administrator/recognition` (`administrator.recognition`) | any admin | the page (`?month=YYYY-MM`) |
| `GET /administrator/recognition/suggestions` | any admin | the same suggestions as JSON |
| `POST /administrator/recognition/{approve,skip,add}` | super admin, throttled | `month`, `counsellor_id` |
| `GET /administrator/recognition/counsellors?search=&month=` | super admin, 60/min | `{counsellors: [{id, name, username}]}` |
| `POST /administrator/settings/recognition-caps` | super admin, 10/min | `caps[session, help_chat, discussion, post, session_per_client, session_per_group]` |
| `GET /` (`home`) | anyone | `recognisedCounsellors` (`id, name, avatar, profession, cases, month`); `leadingCounsellors` (signed-in only) |
| `GET /counsellor/{id}` (`counsellor.show`) | anyone | `recognitionBadges` (month labels); `recognitions` for the counsellor only |
| `POST /counsellor/recognitions/{id}/consent` | the counsellor, 10/min | `action`: `withdraw` or `restore` |
| `POST /api/requests/{id}` (`requests.respond`) | the counsellor | `response`: `accepted` or `rejected`, for a pending one |

## Test data

From `RecognitionSeeder`, which `DatabaseSeeder` calls; see `documentation/seeded-data.md`, "Counsellor
recognition". It stars the six demo counsellors for **last month (UTC)**, counted from when the seeder ran:

- `sarah_johnson`: 6 stars, **approved** with her consent **pending**;
- `michael_chen`: 5 stars, approved and **accepted**;
- `emma_williams`: 4 stars, approved and **declined**; `david_brown`: 4 stars, tied with her at rank 3;
- `lisa_anderson`: 3 stars;
- `james_wilson`: 2 stars, rank 6, **added by hand** and accepted, but with an **open report** about him;
- and `suspended_counsellor_demo`, accepted but **suspended**.

That is 5 of 5 approved for last month. This month, `james_wilson`, `lisa_anderson`, `david_brown` and `michael_chen`
have 5, 4, 3 and 2 new-rule stars (Home's "Leading").

To add it to an existing local database:

```bash
docker compose exec -u www-data php php artisan db:seed --class=RecognitionSeeder
```

Accounts (the demo password for all but the super admin; see `documentation/seeded-data.md`):

- the seeded super admin, `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`);
- an ordinary admin, `suspension_demo_normal_admin`;
- `sarah_johnson`, `michael_chen`, `emma_williams` for the consent controls;
- `maria_garcia` as an ordinary signed-in user.

## Try it

### The admin page

1. As the super admin, open http://localhost:8000/administrator/recognition. Last month is selected and shows
   "5 of 5 approved". Sarah is rank 1 (*approved*, *consent pending*), Michael *consent given*, Emma *consent
   declined*. Under "Also decided" are James (*added manually*, *open report*) and the suspended demo counsellor
   (*suspended*).
2. Click **skip** on Emma, then **confirm skip**. It reads "4 of 5 approved" and the audit "skipped (was approved)".
   Click **approve** on David Brown, then **confirm approve**: "5 of 5" again.
3. Under **Daily star limits**, set Posts to `101` and save; the field shows an error. Set it to `4` and save; the
   value persists after a reload.
4. Switch to this month; it's empty (nobody has been decided for it).
5. Sign in as `suspension_demo_normal_admin` and open the same page. Everything is visible, but there are no buttons,
   no search, and the limits are read-only.

### The showcase (on a fresh seed, before the admin steps above)

1. **Guest:** sign out and open http://localhost:8000/. "Counsellors of the month" shows last month's label and one
   card: **Dr. Michael Chen**, with "Counsellor of the month, <Month Year>", his profession, up to 3 cases and View
   profile. There is no "Leading" section and no star counts anywhere. His profile (View profile) shows the badge
   under his name and no star box.
2. **Signed in:** sign in as `maria_garcia`. Home shows **Sarah** (pending) and **Michael**, and a "Leading
   Counsellors (current month)" row: Lisa Anderson (1), David Brown (2), Michael Chen (3), with no counts. James isn't
   in either: his open report hides him. Open Emma's profile: no badge (she declined).
3. **The counsellor:**
   - Sign in as `sarah_johnson` and open your profile (`/counsellor/{her id}`). The "Counsellor of the month" card lists
     last month, "Waiting for your answer". Click **Agree to be featured**: "Featured on Home and on your profile". A
     guest now sees her on Home too.
   - Click **Stop featuring me**: she disappears from Home for everyone (her own badge stays, for her). Click
     **Feature me**: she is back.
   - Sign in as `emma_williams`: her card says "You chose not to be featured. Only you see it." and her badge shows
     on her own profile and her own Home only. **Feature me** makes her public.
   - `/profile/your-data`, "As a counsellor", lists the month and the consent.
4. **Hidden and back:** as the super admin, dismiss or resolve the "[Recognition demo: open report]" report at
   `/administrator/reports`. James then shows on Home for everyone, and leads "Leading". Lift
   `suspended_counsellor_demo`'s suspension at `/administrator/suspensions`; their card appears on Home too.
