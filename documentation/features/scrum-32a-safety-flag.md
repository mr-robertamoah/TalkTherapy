# Safety flag, part 1: raise a flag and alert the administrators (SCRUM-32, TT-4.3a)

The first of three PRs:
- **TT-4.3a:** raising a flag, the alerts and the admin list (this PR).
- **TT-4.3b:** handling, re-alerts and withdrawal.
- **TT-4.3c:** the client and guardian notices, and closeout.

Planned and approved with the user on 2026-11-08. See `documentation/decision-log.md`.

**Off by default.** The feature stays hidden until `SAFETY_FLAGS_ENABLED=true`. Its routes return 404,
and the admin menu item, the badge and the counsellor's button are hidden. It stays off in production
until the Ghana Act 846/843 legal read is done.

## What was built

### Raising a flag (counsellors)
- A counsellor sees **"Worried about someone's safety? Raise a safety flag"** in three places:
  - on a 1:1 therapy page, for their client;
  - on a group page, for any current or former member (picked from a list that shows members the way
    the group shows them, so an anonymous member stays anonymous and is identified only by a key);
  - on an urgent help chat they answered, while it's going and for 14 days after it ended.
- **Step 1, how urgent:** Imminent, Urgent or Not urgent. **Imminent** first shows the emergency
  number(s) and says the TalkTherapy team can't send help. "Continue to the flag" is always there.
- **Step 2:**
  - the reason (at least a sentence; only the handling administrator sees it, and it's stored
    encrypted);
  - "Have you discussed this with them?" (Yes / No / It wasn't possible);
  - "Have emergency services been contacted?" (with an optional note).
- **Limits:**
  - One open flag per person per therapy. A second gets "There is already an open safety flag for
    this person here".
  - A counsellor can raise 10 flags an hour.
  - Anyone else gets a 404.
- **It works where other actions are blocked:** on a paused minor's therapy, an unpaid one, with lapsed
  verification, and when only the counsellor role is suspended. A counsellor whose whole account is
  suspended can't flag.
- Raising a flag tells the client nothing and changes nothing they see.

### Alerts (administrators)
- **Who is told:** every administrator who isn't involved, super administrators included. They get an
  email ("Safety alert (Imminent)…") and a notification, and the live **Safety alerts** badge updates.
  None of these name the person, the reason, the therapy or the counsellor.
- **Involved** means the person, their guardian, the flagging counsellor, or anyone in that therapy or
  group (members, former members, counsellors, or a guardian of one of them). Involved administrators
  don't see the flag at all, and it isn't counted in their badge.
- If nobody can be told, it's logged as critical. The fallback mailbox comes in TT-4.3b.

### Administration → Safety → Safety alerts
- **The list:** urgency (as a word and a mark, not colour alone), where it was raised (1:1, group or
  urgent help chat), adult or under 18 (and whether they have a guardian), anonymous, country, when it
  was raised, its status and who is handling it. It never shows a name or the reason, and there is no
  search by person.
- **Acknowledge:** the first administrator to acknowledge handles it. A second gets "Another
  administrator has already acknowledged it".
- **Only the handling administrator** can use **Show who** (the person, where it was raised, and the
  counsellor) and **Show the reason**. Each look is recorded in the Read log as "who a safety flag is
  about" or "a safety flag's reason".
- **Hand on:** a super administrator can hand a flag that's being handled to another administrator who
  isn't involved.
- **History and records:**
  - Everything is recorded in an append-only history (the database refuses changes).
  - Flags can't be deleted.
- The page updates live.

## How to try it

1. **Turn it on.** Set `SAFETY_FLAGS_ENABLED=true` in `.env.docker` (it's already on locally), then run
   `docker compose up -d --force-recreate php queue reverb`. Open http://localhost:8000. The password
   for every account is `password`.
2. **Raise a flag.**
   - As `safety_demo_counsellor`, open "Safety Flag Demo (raise one)" and click **Raise a safety
     flag**.
   - Choose **Imminent**. You'll see the (fake, seeded) emergency number. Continue, then fill in the
     reason and the two questions, and click **Raise the flag**.
   - Mailpit (http://localhost:8027) has "Safety alert (Imminent)…" for each administrator, with no
     name and no reason.
3. **A group.** As the same counsellor, open "Safety Flag Demo Group" and raise a flag. The member
   list shows the anonymous member as "Client (Anonymous User)".
4. **Handle it.**
   - As `suspension_demo_normal_admin`, open **Administration → Safety → Safety alerts**. The badge
     shows how many are open.
   - Click **Acknowledge**, then **Show who** and **Show the reason**.
   - In a second browser, as `suspension_demo_admin` (super), the same flag shows "Being handled by …"
     with no Show buttons, plus "Hand to" to hand it on.
5. **Read log.** As `suspension_demo_admin`, open **Read log**: the looks are listed.
6. **Involved administrators** never see a flag. To check: an administrator who is the flagged
   person's guardian sees nothing, and gets a 404 on its actions. This is covered by tests.

## Test data

`DatabaseSeeder::createSafetyFlagDemoData` (see `documentation/seeded-data.md`):
- **`safety_demo_counsellor`** (Dr. Esi Safety): the counsellor.
- **`safety_demo_minor`** (15, guardian `safety_demo_guardian`): an **open imminent** flag on "Safety
  Flag Demo Therapy".
- **`safety_demo_member`** (anonymous in "Safety Flag Demo Group"): an **urgent** flag the super
  administrator (`suspension_demo_admin`) is handling.
- **`safety_demo_client`**: "Safety Flag Demo (raise one)", for raising a new flag.

## Not in this PR

- **TT-4.3b:**
  - notes, actions, and resolving with an outcome;
  - re-alerts to super administrators (1h / 4h / 24h);
  - the fallback mailbox;
  - withdrawing a flag;
  - the counsellor's status label.
- **TT-4.3c:**
  - the client's and guardians' notices (held for the administrator);
  - account anonymisation;
  - /privacy;
  - an open flag keeping a help chat past its one-year deletion.

## Deployment notes

- **Migration** `2026_11_15_100000_create_safety_flags_table`: two new tables with triggers. Production
  needs `log_bin_trust_function_creators=1`, as for the earlier trigger migrations. **Never roll it back
  in testing or production:** that drops the safety record. A code rollback leaves the tables unused.
- **The switch:** `SAFETY_FLAGS_ENABLED` (off). Don't switch it on in production before TT-4.3b
  (re-alerts and the fallback mailbox) and the legal read.
  - To switch it, change `.env`, then `php artisan queue:restart`.
  - Before switching it off, check `SELECT status, COUNT(*) FROM safety_flags GROUP BY status`. Open
    flags would be left unseen.
- **Keys:** the reason and the history are encrypted, and the history can't be re-encrypted. An old
  `APP_KEY` must stay in `APP_PREVIOUS_KEYS` for as long as the records are kept, and keep each backup
  with its key.
- **Alerts** are queued notifications (mail and database). The live ping goes to each uninvolved
  administrator's own channel and never carries an id.

## Tests

`tests/Feature/SafetyFlagTest.php`. The history triggers also run on MySQL in CI (the `mysql-triggers`
group).
