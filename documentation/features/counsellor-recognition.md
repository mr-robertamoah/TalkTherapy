# Counsellor recognition (SCRUM-464)

Each month a super administrator picks up to 5 "Counsellors of the month" from the counsellors who earned the most
stars under the new star rules. A recognised counsellor is asked whether they agree to be featured. This document
covers what an administrator can do today. Built so far:

- the stars (SCRUM-470, SCRUM-471);
- the recognition backend (SCRUM-473);
- the admin page (SCRUM-474).

Still to come: the per-counsellor fee waiver given with a recognition (SCRUM-475), and the public showcase that
replaces Home's "Starred" section (SCRUM-476). Until SCRUM-476 ships, nothing about a recognition is shown publicly.

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

At the top: **"N of 5 approved for <Month Year>"**. When open reports exist that can't be pinned on one counsellor,
an amber note says how many there are and that they count against nobody.

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
- an *added manually* pill for a counsellor who was added rather than suggested;
- a **Fee waiver** column, which is empty until SCRUM-475 fills it.

Star counts and ranks are shown to every administrator. Counsellors never see them.

### Approve, skip, add (super admin)

- **Approve** / **Skip** on a row, then **confirm approve** / **confirm skip** (two clicks, as on Payouts). Approving
  sends the counsellor a featuring-consent request (on their dashboard, under "Needs your action") and a notification.
  Skipping an approval closes a consent request that hasn't been answered yet.
- **Add a counsellor**: type at least 2 characters of a name or username. Only counsellors who could be recognised
  now are found, at most 10, and never your own counsellor profile. Click **add**, then **confirm add**.
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
and in the audit. A declined or withdrawn recognition will never be shown publicly (SCRUM-476).

### What happened (audit)

Every decision and consent change on the month's recognitions, most recent first. Each entry shows when (GMT), which
counsellor, what happened (for example "skipped (was approved)" or "agreed to be featured"), and who did it. The
actor's name is shown to administrators only. The consent request itself always comes from "TalkTherapy".

### Daily star limits

The most stars a counsellor can earn in a UTC day:

- from held **sessions**;
- from **help chats**;
- from held **discussions**;
- from **posts**;
- **per client** (session and help-chat stars from one client);
- **per group** (group-session stars from one group).

A super administrator edits all six at once and clicks **save limits**. Each limit must be a whole number from 0 to
100, and 0 stops that source from earning stars. The new values apply to the next star awarded. Ordinary admins see
the values read-only. Until a limit is saved, it shows the defaults from `config/recognition.php` (8, 5, 2, 3, 1, 1).

## Routes

| Route | Who | What |
|---|---|---|
| `GET /administrator/recognition` (`administrator.recognition`) | any admin | the page (`?month=YYYY-MM`) |
| `GET /administrator/recognition/suggestions` | any admin | the same suggestions as JSON |
| `POST /administrator/recognition/{approve,skip,add}` | super admin, throttled | `month`, `counsellor_id` |
| `GET /administrator/recognition/counsellors?search=` | super admin, 60/min | `{counsellors: [{id, name, username}]}` |
| `POST /administrator/settings/recognition-caps` | super admin, 10/min | `caps[session, help_chat, discussion, post, session_per_client, session_per_group]` |

## Test data

From `RecognitionSeeder`, which `DatabaseSeeder` calls; see `documentation/seeded-data.md`, "Counsellor
recognition". It stars the six demo counsellors for **last month (UTC)**, counted from when the seeder ran:

- `sarah_johnson`: 6 stars, already **approved** with her consent pending;
- `michael_chen`: 5 stars;
- `emma_williams` and `david_brown`: 4 stars each, tied at rank 3;
- `lisa_anderson`: 3 stars;
- `james_wilson`: 2 stars, rank 6, so not suggested with the default cap of 5.

To add it to an existing local database:

```bash
docker compose exec -u www-data php php artisan db:seed --class=RecognitionSeeder
```

Accounts:

- the seeded super admin, `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`);
- an ordinary admin, `suspension_demo_normal_admin` (the shared demo password; see `documentation/seeded-data.md`);
- `sarah_johnson` (the shared demo password), to answer the consent request.

## Try it

1. As the super admin, open http://localhost:8000/administrator/recognition. Last month is selected and shows
   "1 of 5 approved". Sarah is rank 1 (*approved*, *consent pending*), Emma and David share rank 3, and James isn't
   listed.
2. Click **approve** on Michael Chen, then **confirm approve**. It now reads "2 of 5 approved", and the audit lists
   "approved" by you.
3. Under **Add a counsellor**, type `james`, click **add**, then **confirm add**. James shows under "Also decided"
   with *added manually*.
4. Click **skip** on Sarah, then **confirm skip**. Her row reads *skipped*, and the audit reads "skipped (was
   approved)". Her dashboard request is closed.
5. Under **Daily star limits**, set Posts to `101` and save; the field shows an error. Set it to `4` and save; the
   value persists after a reload.
6. Switch to this month; it's empty until stars are earned this month.
7. Sign in as `suspension_demo_normal_admin` and open the same page. Everything is visible, but there are no buttons,
   no search, and the limits are read-only.
8. Sign in as `sarah_johnson` while her approval is still pending (before step 4, or after re-seeding). Accept the
   request on `/counsellor/dashboard`; the admin page then shows *consent given* and the audit "agreed to be
   featured".
