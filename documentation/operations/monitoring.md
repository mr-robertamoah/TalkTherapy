# Monitoring: guardian pauses, safeguarding exceptions and live calls

What to watch in the logs and the scheduler for the guardian-pause work (SCRUM-288, 381, 382) and
group leaving (SCRUM-73). Logs go to Laravel's default channel (`storage/logs/laravel*.log` in
Docker). The context in each line is ids only, never names or reasons.

## Lines to alert on

| Level | Message (starts with) | Where | What it means | What to do |
|---|---|---|---|---|
| error | `Could not end an expired safeguarding exception.` | `SafeguardingExceptionService::endExpiredExceptions()` | One exception's end couldn't be recorded (context: `exception`, `error`). The exception has **still stopped applying**, because enforcement reads `ends_at`. But the minor wasn't taken out of calls or told about the new pause. | The next minute's sweep retries it on its own. Alert if the same `exception` id keeps coming back. |
| error | `The safeguarding exception expiry sweep failed.` | `routes/console.php` (`end-expired-safeguarding-exceptions`, `onFailure`) | The whole sweep failed (for example, the database was unreachable). | Alert on more than a few in a row. It runs every minute, so one blip heals itself. |
| warning | `Could not take a person out of a live call yet; retrying from the queue.` | `RemovePersonFromLiveCallsAction` | The first try failed or the join lock was busy. A `RemovePersonFromLiveCallJob` was queued. | No action. Watch the rate. |
| error | `Could not take a person out of a live call; they may still be in it.` | `RemovePersonFromLiveCallJob::failed()` | Every retry failed (context: `session_id`, `person`, `reason`, `exception`). **The person may still be in the call.** | Page someone. End the session's call by hand if it is still live. |
| error | `Could not finish pausing a minor's therapies for a missing guardian.` | `HandleGuardianLossJob::failed()` | The queued loss handling threw (context: `ward`, `trigger`). The pause itself is in force, because the gate is live. But the minor or their counsellors may not have been told, or taken out of calls. | Look at the error, then re-run the handling for that ward if needed. The job has `tries = 1` on purpose: a retry could tell people twice. |
| warning | `A best-effort step failed.` | `App\Support\BestEffort` | A side step (a notice, a broadcast, a live-call removal) failed, and the rest went ahead. `step` says which one, for example `guardian-access-notify`, `client-access-notify`, `continuation-counsellor-notify`, or one of the group steps below. | Alert on a spike, or on a step that fails every time. |
| error | `Notifications not pruned: NOTIFICATIONS_RETENTION_DAYS must be a number of at least 7 days.` | `PruneNotificationsAction` (daily 02:30, SCRUM-39) | The nightly notification prune was skipped because the setting is missing a number, or under 7 (context: `configured`). Nothing was deleted. | Fix `NOTIFICATIONS_RETENTION_DAYS`. Alert on any one: it runs once a day. Setting it to 1–6 on purpose is how to pause pruning without a deploy. |
| error | `The notification retention sweep failed.` | `routes/console.php` (`prune-notifications`, `onFailure`) | The prune threw partway through. Batches already deleted stay deleted; the rest go the next night. | Look at the exception. Try it with `php artisan schedule:test --name=prune-notifications`. |
| warning | `Registration refused: too many accounts from one address.` | `RegisteredUserController::store` (SCRUM-400) | One network address (`address`: a keyed hash, never the address) created `REGISTRATIONS_PER_HOUR_PER_ADDRESS` accounts in the last hour. | Many with the **same** `address` across unrelated sign-ups usually means `TRUSTED_PROXIES` isn't set behind the proxy, so everyone looks like one address. Fix that at once. Otherwise, a burst is likely abuse; raise the limit only if real people (carrier NAT) are being refused. |
| warning | `TRUSTED_PROXIES entries ignored.` | `AppServiceProvider::boot` (SCRUM-138) | At boot: entries in `TRUSTED_PROXIES` that would trust more than a proxy (`ignored`: a wildcard, `REMOTE_ADDR`, `PRIVATE_SUBNETS`, a range wider than /8 or /29, an IPv4-mapped IPv6 range, or a typo) were dropped. | Fix the env value now: if the proxy's real address was among them, every visitor looks like the proxy (one sign-up limit, one urgent-help limit, http). |
| warning | `Urgent help push not delivered to some devices.` | `SendHelpPushJob` (SCRUM-399) | Some devices couldn't be reached for a ring or its withdrawal (context: `helpRequest`, `kind`, `failed` count -- never an endpoint). Devices the push service says are gone are deleted on their own. | A steady stream means the VAPID keys changed, or the queue can't reach the push services. The in-app ring and the administrators' alerts still work. |
| alert | a `failed_jobs` row for `App\Jobs\SendHelpPushJob`, or the oldest `jobs` row with `queue = 'urgent'` older than about 60s | the queue (SCRUM-399) | A push job ran out of retries, or nothing is working the `urgent` queue (a worker started without `--queue=urgent,default`). | Check the worker's command line and `failed_jobs.exception`. A `DecryptException` means `APP_KEY` changed without `APP_PREVIOUS_KEYS`. |
| error | `The urgent help sweep failed.` | `routes/console.php` (`sweep-help-requests`, `onFailure`, SCRUM-395) | The every-minute urgent help sweep threw: waiting requests aren't expiring, ended chats aren't closing, and the backstop alerts to administrators aren't going out. | Alert on two or more in a row. `GET /api/health/scheduler` also turns 503 with `helpRequestsOverdue: true` once a request has waited past its expiry plus two minutes. |
| warning | `Urgent help ring skipped: the platform-wide ring cap was reached.` | `RingAvailableCounsellorsAction` (SCRUM-395) | More than `HELP_RINGS_PER_MINUTE` requests from one bucket (`bucket`: `established`, or `new` for new or unverified accounts) asked in one minute; this one (`helpRequest`) wasn't rung. It is still waiting, marked "Not rung" at the top of the admin queue, and administrators were alerted at once; "Ring again" rings it. | A burst in `new` is likely abuse: look at the admin help queue. In `established`: an incident, or the cap is too low for real traffic. To stop all new requests, see "The urgent help kill switch" below. The counter is per cache store (the file store counts loosely under heavy bursts and per host). |

The group steps (SCRUM-73/383), each logged with `group_therapy_id` and `user_id`:

| `step` | What failed | Effect |
|---|---|---|
| `group-leave-live-calls` | Taking the leaver out of the group's live call (it retries from the queue itself, see below). | They may stay in a running call for a while. |
| `group-leave-notify-team` | "A member left your group" to the creator and active counsellors. | Nobody on the team is told. |
| `group-leave-notify-guardians` | "Your ward left" to a minor's guardians. | The guardians aren't told. |
| `group-leave-notify-safeguarding-requester` | "Your request was closed" to a counsellor whose safeguarding exception request for the leaver was closed (also `request`). | The request is still closed; they just aren't told. |
| `group-leave-broadcast-leaver` | `GroupTherapyLeftEvent` to the leaver's other open pages. | Their other tabs stay on the group until reloaded. |
| `group-leave-broadcast-group` | `GroupTherapyMembershipChangedEvent` to the group's open pages. | Other members' pages show the old member count until reloaded. |
| `group-join-notify-guardians` | "Your ward joined" to a minor's guardians, for any way of joining. | The guardians aren't told. |
| `group-join-broadcast-group` | `GroupTherapyMembershipChangedEvent` after a join. | Other members' pages don't refresh until reloaded. |
| `group-membership-request-broadcast-creator` (SCRUM-386) | `GroupTherapyMembershipRequestReceivedEvent` to the group's creator (a counsellor-created group's counsellor user) after someone asks to join (also `request`). The event is queued, so delivery also needs the queue worker. | The creator's open group page and Requests pop-up don't show the request until reloaded; the request itself is saved. |

One more step, outside the groups (SCRUM-387), logged with `request`:

| `step` | What failed | Effect |
|---|---|---|
| `admin-todo-cache-forget` | Clearing the admin overview's cached counts after an admin queue request was created or changed status (`Request::booted()`, `Request::adminQueueChanged()`). | The request is saved or answered as normal; the overview and sidebar badges can show the old counts for up to a minute. |

Counsellor verification (SCRUM-31), logged with `counsellor`:

| `step` | What failed | Effect |
|---|---|---|
| `counsellor-verification-reminder` | Queueing the "your verification ends soon" notice. | The reminder is recorded as sent but the counsellor isn't told; the 7-day one still goes. |
| `counsellor-verification-reminder-admin` | Queueing the notice to the administrator who last accepted them. | That administrator isn't told; the overview's renewals count still shows them. |
| `counsellor-verification-ended` | Queueing the "your verification has ended" notice. | The end is recorded; the counsellor isn't told (it applies anyway). |
| `counsellor-verification-revoked` | Queueing the revocation notice with its reason. | The revocation stands; the counsellor isn't told why. |

The daily sweeps (05:00 and 05:15 UTC) log `The counsellor verification reminder sweep failed.` and
`The ended counsellor verification sweep failed.` -- alert on either, since each runs once a day.
One counsellor failing logs `A counsellor verification reminder failed.` or `Recording an ended
counsellor verification failed.` (with `counsellor`) and the rest carry on. Run one on demand with
`docker compose exec -u www-data php php artisan schedule:test --name=counsellor-verification-reminders`.
A verification stops counting the moment it ends whatever the sweep does; the ended sweep looks back
30 days, so a longer scheduler outage loses only those records and notices.

Counsellors verified before SCRUM-31 shipped get end dates spread from release + 12 months to
release + 12 months + 60 days (one per counsellor, fixed by id -- SCRUM-389), so reminders and ends
trickle in over about two months. Watch the overview's renewals count from about 30 days before
release + 12 months until about two months after.

Preference ranking (SCRUM-113) runs SQL on every Home and Public list request. If one of those
queries fails, Laravel's exception report includes its bindings -- the viewer's own preference ids
(cases, languages, religions). Treat those logs as personal data.

Related lines worth a dashboard but not a page: `Could not send a safeguarding exception reminder.`,
`The safeguarding exception reminder sweep failed.` (hourly) and `Could not queue the announcement
of an existing pause.` (backfill, below).

## Info keys (for dashboards and audits)

- `guardian_access.paused`: a minor's therapies were paused. Context: `ward`, `trigger`,
  `therapies`, `groups`, `video_rooms_left`.
- `guardian_access.resumed`: a minor's therapies started again. Context: `ward`, `trigger`,
  `therapies`, `groups`.
- `guardian_access.resume_not_announced`: the pause lifted, but nothing could carry on (everything
  else was still blocked, or nothing was open), so nobody was told.
- `guardian_access.existing_pauses_announced`: the backfill queued these wards (`wards`).
- `safeguarding_exception.granted` / `safeguarding_exception.ended`: who granted or ended which
  exception (`exception`, `ward`, `by`, plus `request`/`renews` on a grant).
- `group_therapy.member_left` (SCRUM-73): a member left a group. Context: `group_therapy_id`,
  `user_id`.
- `Two-factor sign-in reset by an administrator` (SCRUM-34c, notice): a super administrator turned off someone's
  two-factor sign-in (lost phone and recovery codes). Context: `admin_id`, `user_id`, `ip` -- ids only. It's the
  record of the reset (there is no table): resets should be rare, so review each one against the support request
  that confirmed who the person was; a burst, or an unexpected `admin_id`, may mean a taken-over super-admin account
  or someone talking support into resets.
- `Two-factor sign-in reset on the server` (SCRUM-34d, notice): someone with shell access ran `user:two-factor-reset`.
  Context: `user_id`, `admin` (whether it was an administrator's account), `by` (the sudo user, else the process
  owner -- often just `www-data`, so match it to the SSM Run Command / Session Manager history for that time), `host`,
  `forced` (the confirmation was skipped). Every
  one should match a support request; one with `admin: true` that nobody can account for means the server access
  itself needs looking at.
- `TWO_FACTOR_ADMINS_REQUIRED_FROM is not a date (YYYY-MM-DD); …` (SCRUM-34d, warning, at most once per request): the
  configured date couldn't be read, so two-factor is **not** required for administrators. Context: `value`. Fix the
  `.env` and `php artisan config:clear`. Alert on any occurrence -- a requirement you think is on isn't.
- `Notifications pruned.` (SCRUM-39, daily): what the notification prune deleted for good. Context:
  `deletedByDays` (count per retention period in use), `ownerless` (notifications of owners deleted
  for good), `defaultDays`. A sudden jump usually means the default was lowered.

A sudden jump in `guardian_access.paused` usually means guardianships were removed in bulk, or many
dates of birth were changed. Check it against the admin log.

## The live-call retry

`RemovePersonFromLiveCallJob`: `tries = 4`, `backoff = [10, 30, 90]` seconds, so it gives up about
2 minutes 10 seconds after the first failure. Each run re-checks under the person's video join
lock, by the same policy (guardian loss, suspension, or no longer a member), so a retry never
removes someone who was let back in the meantime. Only the final failure logs an error (above). A
growing `failed_jobs` count for this class means video removal is broken.

## The every-minute sweep

`end-expired-safeguarding-exceptions` runs every minute (`withoutOverlapping(10)`) from the `queue`
container's `schedule:work`. It only does the side effects. An exception stops applying at
`ends_at` even if the sweep is down. So a stopped scheduler means no "paused again" notices and no
live-call removal, but never a minor who keeps writing past the end.

- Watch the scheduler as a whole through `GET /api/health/scheduler`. It returns 503 when the
  heartbeat is older than 5 minutes (SCRUM-373).
- `safeguarding-exception-expiry-reminders` runs hourly (`withoutOverlapping(30)`).
- Check by hand:
  `docker compose exec -u www-data php php artisan schedule:test --name=end-expired-safeguarding-exceptions`.

## Running the backfill (once, after deploying SCRUM-382)

```bash
docker compose exec -u www-data php php artisan guardians:announce-existing-pauses --dry-run
docker compose exec -u www-data php php artisan guardians:announce-existing-pauses
```

- **Always run it as `www-data`** (in production, the web server's user), never root. It writes a
  marker for each account to the scheduler's cache store, and files root creates under `storage/`
  can't be written by the app afterwards (see CLAUDE.md).
- It exits with 1, and lists the accounts it couldn't queue, when queueing fails for any of them.
  Running it again retries just those accounts, because the others keep their markers.
- The work itself runs on the queue (`HandleGuardianLossJob`), so the worker must be up and running
  the new code: run `docker compose restart queue` after deploying.

## After deploying SCRUM-73/383

- **Restart the queue** (`docker compose restart queue`): a running worker has the old code in
  memory and doesn't know the `no_longer_member` removal reason of `RemovePersonFromLiveCallJob`, so
  a leaver's retried live-call removal would fail until it restarts.
- `GroupTherapyMembershipRequestAcceptedGuardianNotification` is now
  `GroupTherapyMemberJoinedGuardianNotification`. A notice of the old class still waiting in the
  queue at deploy fails to unserialise; drain the queue first if any can be pending.

## Closed accounts and stored notices (WardRef)

Anonymising a closed account (`AnonymiseClosedAccountAction`) replaces the person's name in other
people's stored notices that carry their `wardRef` (an HMAC of their id with `APP_KEY`): the pause,
resume and continuation notices, and "a member left your group".

- **Rotating `APP_KEY`:** keep the old key in `APP_PREVIOUS_KEYS` for as long as notices written
  under it may still need renaming. Refs are matched under the current key and every previous one;
  once a key is dropped from that list, notices written under it keep the name.
- **The lookup (SCRUM-386):** the ref is also an indexed generated column, `notifications.ward_ref`
  (stored on MySQL, virtual on SQLite), so these rows are found by an index lookup on it -- no
  LIKE over `data`, no table scan. It is a plain non-locking read, and each match is updated by its
  primary key. A row whose `data` isn't valid JSON, or has no ref (or a JSON null one), has a null
  `ward_ref` and is never matched. Adding the column rebuilt the `notifications` table on MySQL
  (deploy it in a quiet window). Neither the column nor `data.wardRef` is ever serialised
  (`App\Models\DatabaseNotification`).

## Admin overview caching (SCRUM-387)

The admin overview's to-do counts and the sidebar badges (`GetAdminToDoCountsAction`, the badges
through `GET /administrator/badges`) are cached in the app's default cache store:

| Key | What | TTL |
|---|---|---|
| `admin-todo:generation` | A random generation in every key below except the paused-minors one. Moving it on (`GetAdminToDoCountsAction::forget()`) is how they are cleared: whenever an admin queue request is created or changes status. | forever |
| `admin-todo:{generation}:shared` | The counts every administrator sees, worked out without a viewer. | 60 s |
| `admin-todo:{generation}:super` | Super administrators' safeguarding total, "no longer needed" and failed jobs. | 60 s |
| `admin-todo:{generation}:user:{id}` | One super administrator's safeguarding split (can answer / involved). | 60 s |
| `admin-todo:paused-minors` | Paused minors with an open therapy and no exception -- the expensive one, on the overview only. Outside the generation: answering a request doesn't change it. | 5 min |
| `admin-platform-stats` | The platform stats (`GetPlatformStatsAction`), shown with an "as of" time. Not cleared by answers. | 15 min |

- `php artisan cache:clear` (as `www-data`) resets all of them; the next overview works them out
  again.
- An old generation's keys aren't deleted, they just expire.
- The cache is the single node's file store in Docker. With more than one app node, the store must
  be shared (Redis, database) for a clear on one node to reach the others.

## Websocket subscriptions after leaving

The websocket server doesn't re-authorise a subscription a member already holds, so a leaver's open
pages could stay on the group's presence channel (its roster and broadcasts) until they reconnect.
Nothing closes that subscription server-side: Reverb v1.10's terminate-connections API
(`UsersTerminateController`) reads `user_id` from every connection's first channel and answers 500
when that is a private channel (as `private-users.{id}` always is in this app). A leave run against
the live stack with an open subscription logged `group-leave-terminate-presence ... Internal server
error.` and left the connection open, so the call was removed (as for suspensions, SCRUM-366).

What keeps a leaver off the group: their leaving page and `GroupTherapyLeftEvent` take every open
page of theirs off the group and release the channel, and every later subscription is refused
(`routes/channels.php` checks `isParticipant()`). **Residual risk:** a deliberately modified client
keeps its existing subscription until it disconnects, and so keeps seeing the presence roster
(broadcast keys and display names) and the group's broadcasts. Those carry ids only (session ids,
payload-less membership/access changes), never message content; everything they point to is
fetched through the API, which refuses a former member. The same holds for a session chat channel
(`sessions.{id}`) they already had open, which does carry messages: a modified client that ignores
`GroupTherapyLeftEvent` keeps receiving that session's new messages until it disconnects. Closing
these server-side waits on a Reverb terminate API that works with private channels (SCRUM-366).

## Urgent help: the health check and the kill switch (SCRUM-395)

- `GET /api/health/scheduler` also returns 503, with `helpRequestsOverdue: true`, when an urgent help request
  has been waiting past `HELP_EXPIRE_AFTER_MINUTES` plus two minutes: the minute sweep that expires them has
  stopped or is failing. One stuck request is enough, even with the heartbeat fine. It says only yes or no,
  never which request.
- **The kill switch.** To stop new urgent help requests (requests already made carry on, and the dialog still
  shows the emergency numbers): set `HELP_ENABLED=false` in `.env.docker` (or the server's env), then
  `docker compose up -d --force-recreate php queue` -- containers read their env file only when they are
  created, so `config:clear` alone changes nothing. If config is cached, run `php artisan config:cache` after.
  Back on: `HELP_ENABLED=true` and the same.


## Email changes (SCRUM-422)

- `EmailChangedNotification` in `failed_jobs`: the warning to an account's previous address didn't go out. It's
  the only notice the real owner gets if someone else changed the email -- check the mail settings and retry
  (`php artisan queue:retry`). It needs a running queue worker (`default` queue).
## Signed-out tabs (SCRUM-423)

- `GET /session/check` answers 401 to an ended session by design -- leave it out of any 4xx-rate alert. A burst of
  429s there means a page checking far more often than it should.
- `SessionCheckRequestedEvent` rows in `failed_jobs` (or rescued exceptions) mean the nudge isn't reaching Reverb:
  open tabs then find out about an ended session only on their next request. Nothing is less secure (EndStaleSessions
  still refuses them).
