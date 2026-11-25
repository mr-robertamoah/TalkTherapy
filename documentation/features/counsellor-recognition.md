# Counsellor recognition (SCRUM-464)

Each month a super administrator picks up to 5 "Counsellors of the month" from the counsellors who earned the most
stars under the new star rules. A recognised counsellor is asked whether they agree to be featured. Built:

- the stars (SCRUM-470, SCRUM-471, and stars from likes and comments on posts, SCRUM-472 -- see "How stars are
  earned" below);
- the recognition backend (SCRUM-473);
- the admin page (SCRUM-474);
- the per-counsellor fee waiver backend (SCRUM-475), and its place on the admin page (SCRUM-477): an optional
  "Also waive the platform fee" when approving, and a **Fee waiver** column;
- the public showcase, the profile badge and the counsellor's consent controls (SCRUM-476, below).

## How stars are earned

Only stars from these rules count towards recognition (older stars stay on the database but never count). A star
always goes to the counsellor's profile, and only while the counsellor is verified, not suspended and not deleted.

- **A held session** (help chats included), with proof both sides took part (SCRUM-471).
- **A held discussion** with at least 2 people writing in its window (SCRUM-471).
- **A post** published as a counsellor (SCRUM-471).
- **Likes and comments on a counsellor's post (SCRUM-472).** Each person who likes it, comments on it, or both gives
  the counsellor **one** star for that post. Liking twice, or liking and commenting, is still one star. Anonymous
  comments count and stay anonymous. The person must:
  - not be a counsellor at all, which also rules out the post's author (a deleted counsellor profile still counts
    as one);
  - have a verified email;
  - have an account at least 7 days old (`recognition.interaction_min_account_age_days`);
  - not be suspended (either kind), deleted or anonymised.

  The star is worked out again after every like, unlike, comment and comment delete (a deleted comment counts for
  its author). It stays while the person still has a like or a comment on the post, and goes once they have neither
  (or no longer qualify). **A post earns a star from each person once, ever:** every award is recorded for good
  (`post_interaction_awards`), so unliking and liking again gives nothing new, and can't move a star into a later
  month. A post made as a user, not as a counsellor, earns nothing. Deleting the post deletes its stars and its
  award records. An unlike on a deleted counsellor's post still takes the star back.

  When the person goes, so do their stars: suspending them (either kind) deletes the post-interaction stars they gave
  this UTC month; closing their account deletes every post-interaction star they gave and takes their name off any
  other star (anonymising a closed account does the same again, for accounts closed earlier).

  Liking the same thing twice is refused ("You already like this.", 422). Liking, unliking, commenting and deleting a
  comment are each limited to 30 a minute per person. Who liked or commented is never shown to anyone: no page or
  API sends who a star came from.

Every rule has a limit; see "Star limits" below.

## The admin page: `/administrator/recognition`

In the admin sidebar under **Money**, next to Payouts, as **Counsellor recognition**.

- **Every administrator** can open it and see everything on it: the suggestions, the decided rows, the audit and the
  star limits.
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

- the **fee waiver** linked to the recognition, if any (see below).

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

### Fee waiver (SCRUM-477)

**When approving** (a suggestion, an "Also decided" row, or **add**), a super administrator gets an optional
**"Also waive the platform fee"** checkbox, off by default. Ticking it shows:

- **Starts (UTC/GMT)** and **Ends (UTC/GMT)**, prefilled with the next calendar month: the 1st of next month 00:00
  UTC to the 1st of the month after (in December, January 1st to February 1st of the next year);
- a **Reason**, prefilled "Counsellor of the month, <Month Year>" (the recognition's month). Administrators only;
  the counsellor is told the dates, never the reason.

Focus moves to the checkbox when the panel opens. The option isn't offered on a row whose recognition already has a
linked period (a recognition links to one period at most).

The fields are checked before anything is posted: both dates and a reason (up to 500 characters), the end after the
start, and the start not in the past (a minute's leeway, as on the server). Each problem shows under its field, and
**confirm approve** / **confirm add** stays disabled until they pass.

**confirm approve** (or **confirm add**) saves the approval first. Only if that succeeds is the period created, as a
commission-free period of this counsellor's own linked to the new recognition (`POST
/administrator/counsellors/{id}/fee-waivers` with `counsellor_recognition_id`). If the server refuses the period, the
approval stays, and a red notice above the suggestions (kept until dismissed) says **"<Name> approved without a fee
waiver."** (or **"added"**), followed by the reason. Reasons the page can't check itself include:

- it overlaps another of the counsellor's own periods;
- the counsellor isn't eligible now (an open report, suspended, verification lapsed).

No other decision can be confirmed until the period has been saved or refused.

There is no "add a waiver" for a row already approved without one; a super admin who needs one later can skip and
re-approve the row, or use the JSON route directly (see `commission-free-periods.md`).

**The Fee waiver column**, on suggestions and "Also decided" rows alike, shows the period linked to that row's
recognition:

- its start and end (GMT) and its status: *upcoming*, *active*, *ended* or *cancelled* (ended early with "End now");
- a red **needs review** pill while it is upcoming or active but the counsellor is no longer eligible, or the
  recognition is no longer approved. Periods are never ended automatically; the administrator decides. A report
  about an urgent help request (which hides who asked for help) never shows here, though it still stops a new
  period;
- for a **super administrator**, its reason, plus **end now** (while active) or **delete** (while upcoming), each with
  a confirm click;
- **--** for a row without a linked period.

An **ordinary administrator** sees the dates, the status and *needs review*, but not the reason and no buttons. On
the viewer's **own** row (an administrator who is also a counsellor) the column only says **waiver given**: no dates,
status or *needs review*, which could reveal an open report about them.

The column comes in the page props (`feeWaivers`), read in a fixed number of queries for the month; the
per-counsellor JSON list stays super-admin only. When the table is wider than the window, it scrolls sideways rather
than clipping the last column.

### Consent

The counsellor answers the request on their dashboard (accept or decline). Afterwards they can withdraw their
consent or give it again (`POST /counsellor/recognitions/{id}/consent`). Each change shows on the row's consent pill
and in the audit. A declined or withdrawn recognition is never shown to anyone but the counsellor (see "Who sees a
recognition" below).

### What happened (audit)

Every decision and consent change on the month's recognitions, most recent first. Each entry shows when (GMT), which
counsellor, what happened (for example "skipped (was approved)" or "agreed to be featured"), and who did it. The
actor's name is shown to administrators only, and never on the viewer's own recognition. The consent request itself always comes from "TalkTherapy".

### Star limits

The most stars a counsellor can earn in a UTC day:

- from held **sessions**;
- from **help chats**;
- from held **discussions**;
- from **posts**;
- **per client** (session and help-chat stars from one client; 0 stops every session and help-chat star that has a
  client);
- **per group** (group-session stars from one group);
- from **likes and comments** on their posts (SCRUM-472);
- **likes and comments per client** (like-and-comment stars from one client, SCRUM-472);

and, not per day, **per post (ever)**: the most stars one post can ever earn from likes and comments (SCRUM-472).
The three like-and-comment limits count awards, not current stars, so a star taken back by an unlike never frees a
place: once a post's limit is reached, more interactions earn nothing.

A super administrator edits all nine at once and clicks **save limits**. Each limit must be a whole number:

- from 0 to 100;
- from 0 to 10 for the per-client and per-group limits (likes and comments per client included).

0 stops that source from earning stars. A save changes all nine or none. Every change is logged (`Daily star caps
changed.`), with who made it and the old and new values. The new values apply to the next star awarded. Ordinary admins see
the values read-only. Until a limit is saved, it shows the defaults from `config/recognition.php` (8, 5, 2, 3, 1, 1,
then 20 likes and comments a day, 1 per client a day and 10 per post).

## The showcase (SCRUM-476)

### Who sees a recognition

One rule, `GetVisibleRecognitionsAction`, decides it for Home, the profile badge and nothing else:

- only **approved** recognitions;
- a **guest** sees those the counsellor **accepted**;
- a **signed-in** user also sees those still **pending** an answer;
- the **counsellor** also sees their own **declined** and **withdrawn** ones;
- **nobody** sees any while the counsellor is suspended, has an open or under-review report about them, has a lapsed
  or revoked verification, or has deleted their profile or account. It comes back as soon as that clears. Nothing on
  the page says why a recognition isn't shown.
- a report about an **urgent help request** never hides anyone publicly (the user's decision, 2026-11-23): someone who
  reported a help request could otherwise watch Home and see who disappeared, and so learn who asked for help. Such a
  report still stops a new recognition or a new fee waiver on the admin side.

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
so far this UTC month, among those who could be recognised now (verified, not suspended or deleted) and not hidden by
an open report (the same reports that hide a recognition). Legacy stars never count. Ranked like the admin
suggestions: most stars, then the earliest first star, then the lowest id. The cards show the position but no counts.
The star ranking is cached for 5 minutes, so a newly earned star can take that long to show; who is suspended,
unverified or reported is checked on every request. Home reads the open reports once for both rows, and only those
that could be about the counsellors on them.

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

### Reporting (SCRUM-476 hardening)

Because an open report hides a counsellor, `POST /api/reports` is limited to 10 a minute per person, and a person can
have only one open (or under-review) report on the same therapy, session, group or post naming the same person at a
time: a second one is
refused with "You have already reported this, and that report is still open." Once the first is closed, they can
report it again.

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
| `POST /administrator/counsellors/{counsellor}/fee-waivers` | super admin, 10/min | `starts_at`, `ends_at`, `reason`, `counsellor_recognition_id` (the page posts it after an approval) |
| `POST /administrator/counsellors/{counsellor}/fee-waivers/{waiver}/{end,delete}` | super admin, 10/min | the column's **end now** / **delete** |
| `GET /` (`home`) | anyone | `recognisedCounsellors` (`id, name, avatar, profession, cases, month`); `leadingCounsellors` (signed-in only) |
| `GET /counsellor/{id}` (`counsellor.show`) | anyone | `recognitionBadges` (month labels); `recognitions` for the counsellor only |
| `POST /counsellor/recognitions/{id}/consent` | the counsellor, 10/min | `action`: `withdraw` or `restore` |
| `POST /api/requests/{id}` (`requests.respond`) | the counsellor | `response`: `accepted` or `rejected`, for a pending one |

## Test data

From `RecognitionSeeder`, which `DatabaseSeeder` calls; see `documentation/seeded-data.md`, "Counsellor
recognition". It stars the six demo counsellors for **last month (UTC)**, counted from when the seeder ran:

- `sarah_johnson`: 6 stars, **approved** with her consent **pending**, and her own commission-free period for the
  whole of next month (UTC) linked to it (*upcoming* in the Fee waiver column);
- `michael_chen`: 5 stars, approved and **accepted**;
- `emma_williams`: 4 stars, approved and **declined**; `david_brown`: 4 stars, tied with her at rank 3;
- `lisa_anderson`: 3 stars;
- `james_wilson`: 2 stars, rank 6, **added by hand** and accepted, but with an **open report** about him;
- and `suspended_counsellor_demo`, accepted but **suspended**.

That is 5 of 5 approved for last month. This month, `james_wilson`, `lisa_anderson`, `david_brown` and `michael_chen`
have 5, 4, 3 and 2 new-rule stars (Home's "Leading").

Stars from post interactions (SCRUM-472) come from `PostInteractionStarSeeder` (also called by `DatabaseSeeder`; see
`documentation/seeded-data.md`, "Stars from post interactions"): `lisa_anderson` has a counsellor post starting
"[Recognition demo: post] Small routines help on hard days", liked by `post_fan_ama` (who also commented) and
`post_fan_kofi` (2 stars today), and by `post_fan_new`, whose account is only 2 days old (no star). `post_fan_esi`
qualifies and hasn't touched the post. That makes Lisa's stars this month 6.

To add it to an existing local database:

```bash
docker compose exec -u www-data php php artisan db:seed --class=RecognitionSeeder
docker compose exec -u www-data php php artisan db:seed --class=PostInteractionStarSeeder
```

Accounts (the demo password for all but the super admin; see `documentation/seeded-data.md`):

- the seeded super admin, `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`);
- an ordinary admin, `suspension_demo_normal_admin`;
- `sarah_johnson`, `michael_chen`, `emma_williams` for the consent controls;
- `maria_garcia` as an ordinary signed-in user;
- `post_fan_esi` (qualifies) and `post_fan_new` (account too new) for post-interaction stars.

## Try it

### The admin page

1. As the super admin, open http://localhost:8000/administrator/recognition. Last month is selected and shows
   "5 of 5 approved". Sarah is rank 1 (*approved*, *consent pending*) and her Fee waiver column shows her seeded
   period (*upcoming*) with **delete**. Michael is *consent given*, Emma *consent declined*. Under "Also decided" are
   James (*added manually*, *open report*) and the suspended demo counsellor (*suspended*).
2. Click **skip** on Emma, then **confirm skip**. It reads "4 of 5 approved" and the audit "skipped (was approved)".
3. Click **approve** on David Brown. A grey panel opens under his row. Tick **Also waive the platform fee**: the
   dates read the 1st of next month 00:00 to the 1st of the month after, and the reason "Counsellor of the month,
   <last month>". Set the start to a time in the past: "The start cannot be in the past." shows under it and
   **confirm approve** is disabled. Put the start back, then click **confirm approve**. It reads "5 of 5 approved",
   the success alert says the fee was waived, and his Fee waiver column shows the dates and *upcoming*.
4. Click **skip** on Sarah, then **confirm skip**. Her row reads *skipped* and her dashboard request is closed. Her
   period is not ended; its column now also shows **needs review**. Click **delete**, then **confirm delete**: the
   column reads **--**.
5. Under **Star limits**, set Posts to `101` and save; the field shows an error. Set it to `4` and save; the
   value persists after a reload.
6. Switch to this month; it's empty (nobody has been decided for it).
7. Sign in as `suspension_demo_normal_admin` and open the same page. Everything is visible, but there are no buttons,
   no search, and the limits are read-only. David's Fee waiver column shows the dates and *upcoming*, but no reason
   and no buttons.

### Stars from likes and comments (SCRUM-472)

1. As the super admin, open http://localhost:8000/administrator/recognition and switch to **this month**. Note Lisa
   Anderson's stars (6 on a fresh seed).
2. In another browser, sign in as `post_fan_esi` and open Home. In the posts list, find Lisa's post starting
   "[Recognition demo: post] Small routines help on hard days". Like it, then add a comment.
3. Reload the admin page: Lisa has **one** more star (7), not two.
4. As `post_fan_esi`, unlike the post: still 7 (the comment remains). Delete the comment: back to 6. Like it
   again: still 6 (each person earns a post its star once, ever).
5. Sign in as `post_fan_new` and like the post: no change (the account is 2 days old). As `lisa_anderson`, like your
   own post: no change.
6. Under **Star limits**, the card lists **Likes and comments** (20), **Likes and comments per client** (1) and
   **Per post (ever)** (10) with their hints. Set Per post (ever) to `101` and Likes and comments per client to `11`
   and save: both fields show an error. Set Per post (ever) to `2` and save: after a reload it reads 2. Put it back
   to 10.
7. Nowhere -- the post, Lisa's profile, Home -- shows who liked or commented beyond the existing counts.

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
     last month, "Waiting for your answer". Click **Agree to be featured**: "Featured on Home and on your profile while your profile is verified and
     in good standing". A
     guest now sees her on Home too.
   - Click **Stop featuring me**: she disappears from Home for everyone (her own badge stays, for her). Click
     **Feature me**: she is back.
   - Sign in as `emma_williams`: her card says "You chose not to be featured. Only you see it." and her badge shows
     on her own profile and her own Home only. **Feature me** makes her public.
   - `/profile/your-data`, "As a counsellor", lists the month and the consent.
4. **Hidden and back:** as the super admin, dismiss or resolve the "[Recognition demo: open report]" report at
   `/administrator/reports`. James then shows on Home for everyone, and leads "Leading". Lift
   `suspended_counsellor_demo`'s suspension at `/administrator/suspensions`; their card appears on Home too.
