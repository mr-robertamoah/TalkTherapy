# Safety flag, part 3: the person's and guardians' notices, and closeout (SCRUM-32, TT-4.3c)

This is the last of three PRs:
- **Part 1:** raising a flag and alerting the administrators. See
  `documentation/features/scrum-32a-safety-flag.md`.
- **Part 2:** handling, re-alerts and withdrawal. See
  `documentation/features/scrum-32b-safety-flag-handling.md`.
- **Part 3:** the notices and closeout (this PR).

The decisions are in `documentation/decision-log.md`. It is all still behind `SAFETY_FLAGS_ENABLED`
(off). Before switching it on in production you need:
- the Ghana Act 846/843 legal read, which also covers the notice wording and /privacy;
- `SAFETY_FALLBACK_MAILBOX` set;
- external alerting on the scheduler, the queue and critical log lines.

## What was built

### The notices
Two notices can go out when a flag is raised:
- **To the person:** a neutral message making sure they know how to get help ("The TalkTherapy team
  wants to make sure you have the support you need right now…", with **Get help now**).
  - It goes in the app only, never by email.
  - It never mentions a flag, the reason, the counsellor or the therapy.
- **To a minor's guardians,** on imminent and urgent flags: "please check in with {first name} and make
  sure they have the support they need".
  - It goes in the app and by email.
  - It names only their ward. It never names the therapy or the counsellor, and never reveals an urgent
    help chat.

### When they go out (the user's decisions)
- **Held for the administrator.** While nobody has acknowledged the flag, the notices go out on their
  own at its re-alert time (1h, 4h or 24h by urgency).
- **Once someone acknowledges it,** the handler sees **Notices** with three choices:
  - **Send now**;
  - **Hold…**, with a safety reason. A held notice waits for them and never goes out on its own;
  - **Withhold…** for good, with a reason.

  If the handler does nothing, the notices go out on their own at the re-alert time after acknowledging.
- **Guardians:** the counsellor can give a safeguarding reason not to tell the guardians when raising
  the flag. That holds the guardians' notice for the administrator, whose decision is final.
- **Before resolving,** every notice still waiting or held must be sent or withheld.
- **When the counsellor withdraws the flag:**
  - **Before anyone acknowledged it:** its unsent notices are cancelled.
  - **After acknowledgement:** the notices wait for the handler.
- Every decision is recorded in the history, which never shows the reason. The reasons are encrypted and
  shown only to the handler, under **Show the reason**.

### The person's notice in the app
- The notice opens as a dialog on their next visit. It keeps opening until they choose **I've read
  this**. **Get help now** opens the "I need help now" dialog.
- A suspended account still gets it.
- There is no notification list yet (SCRUM-395, item 9). This notice is the in-app channel.

### Closeout
- **Closing the person's account** (anonymisation) erases every bit of free text in flags about them:
  - the counsellor's reason and emergency-services note;
  - the withdrawal reason, the hold and withhold reasons, and the outcome note;
  - every note's content.

  The flags and their history stay. Their guardians' notices name "a closed account" instead.
- **An urgent help chat with an unresolved safety flag** is kept past its one-year deletion. Unresolved
  means open, being handled, or withdrawn after someone took it on.
- **/privacy** has a "When a counsellor is worried about your safety" section, pending the legal read.

## How to try it

`SAFETY_FLAGS_ENABLED=true` is set in `.env.docker` (on locally). Open http://localhost:8000. The
password for every account is `password`. Mailpit is at http://localhost:8027.

1. **Raise a flag with a guardian.**
   - As `safety_demo_counsellor`, open "Safety Flag Demo Therapy" (`safety_demo_minor`, 15, guardian
     `safety_demo_guardian`).
   - Raise an **urgent** flag. Optionally fill in "a safeguarding reason not to tell their guardians".
2. **Handle the notices.**
   - As `suspension_demo_normal_admin`, open **Safety alerts** and **Acknowledge** the flag.
   - Under **Notices**: **Send now** the person's notice, and **Hold…** or **Send now** the guardians'.
   - **Resolve…** is refused while a notice is waiting or held.
3. **What the person sees.**
   - As `safety_demo_minor`, any page shows "A message from the TalkTherapy team". **I've read this**
     dismisses it.
   - As `safety_demo_guardian`, the dialog says "please check in with Kwame", and Mailpit has "Please
     check in with Kwame". Neither says why.
4. **Automatic sending.**
   - Leave a new flag alone.
   - At its re-alert time the notices go out on their own. You can set
     `SAFETY_REALERT_MINUTES_URGENT=1`, recreate the containers, and wait for the five-minute sweep
     (`send-due-safety-notices`).
5. **Withdraw before anyone acknowledges.** Raise a flag, then withdraw it straight away. The admin row
   shows its notices as "Cancelled".
6. **/privacy:** open "Safety concerns".

## Test data

`DatabaseSeeder::createSafetyFlagDemoData` (see `documentation/seeded-data.md`):
- the withdrawn flag's notice is **cancelled**;
- the resolved flag's notices were **sent**.

## Deployment notes

- **No migration:** the notice columns came with TT-4.3a.
- **New schedule** `send-due-safety-notices`, every five minutes. It does nothing while the feature is
  off.
- **The person's notice routes** (`safety_notice.current`, `safety_notice.dismiss`) are open to
  suspended accounts.
- **The notice wording** is in `SafetyNoticeNotification::message()` and
  `GuardianSafetyNoticeNotification::message()`. It is pending the legal read.

### SCRUM-32 go-live checklist (before `SAFETY_FLAGS_ENABLED=true` in production)
1. **The Ghana Act 846/843 legal read** of the label, the notice wording, the outcome list and /privacy.
   That includes whether a guardian notice lets the guardian infer that the minor saw a counsellor.
2. **`SAFETY_FALLBACK_MAILBOX`** set to an inbox someone watches.
3. **Exactly one scheduler and a supervised queue worker** on the host, with `QUEUE_CONNECTION` not
   `sync`. Without the worker nobody is told anything; without the scheduler there are no re-alerts and
   no automatic notices.
4. **Alerting** on failed jobs (to someone who watches), and on the log lines "The safety flag re-alert
   sweep failed." and "The safety notice sweep failed." Also alert on critical log lines generally.
5. **Mail deliverability** (SPF and DKIM) for the production sender: guardian emails matter.
6. **`log_bin_trust_function_creators=1`** for the TT-4.3a/b triggers.
7. **A data check** wherever an earlier part ran with the feature on (testing, local):
   `select id, status, client_notice_state, guardian_notice_state from safety_flags where status in ('RESOLVED','WITHDRAWN') and (client_notice_state in ('PENDING','HELD') or guardian_notice_state in ('PENDING','HELD'));`
   Set those to `CANCELLED`.

### Switching it on or off
- Change `SAFETY_FLAGS_ENABLED`, then `php artisan config:cache` (or `config:clear`) **and**
  `php artisan queue:restart`. Workers keep the old value until restarted.
- **Before switching it off:** check `select status, count(*) from safety_flags group by status`. Open
  flags are left unseen, and notices already queued are dropped by `shouldSend`.
- **Switching it back on** sends every overdue waiting notice at once, possibly days late. Review the
  waiting ones first.

### Rolling back
- **Code back to TT-4.3b:** first change `CANCELLED` and `NO_RECIPIENT` notice states to a state 4.3b
  knows (for example `WITHHELD`), and let the queue drain of the two notice classes.
- **Never roll back the TT-4.3a/b migrations** in testing or production.

## Tests

`tests/Feature/SafetyFlagNoticesTest.php`, plus `SafetyFlagHandlingTest.php` and `SafetyFlagTest.php`.
