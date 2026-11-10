# Ops and architecture follow-ups, Paystack resilience (SCRUM-373, 374, 375, 272, 248, 193)

This is one PR. The user asked for the next set of follow-ups, grouped with existing tickets. The
reasoning behind each change is in `documentation/decision-log.md`, under "SCRUM-373 + 374 + 375 +
272 + 248 + 193".

## What was built

### SCRUM-375: times outside UTC
- **The countdown is now right in every timezone.** Session and discussion countdowns ("starts in",
  "to end") used to be off by the viewer's UTC offset: hours out in New York or Berlin, and correct
  only in places at UTC+0, such as Ghana. Times are stored as true UTC, so the adjustment is gone.
- **Form times are local.** The create and edit forms for sessions and discussions now fill their
  default and earliest allowed times in the viewer's local time. They used to fill in UTC, which a
  date-time input then reads as local time.
- **A discussion's therapy can't change.** A discussion always stays with its therapy, and the
  update always kept the same therapy anyway. The misleading code that looked as if it could move one
  was removed.

### SCRUM-373: operations
- **Restart policies:** `vite`, `web` and `queue` now come back after a host restart. After a
  suspend on 2026-11-02, the scheduler (in `queue`) silently stopped for about 7.5 hours.
- **Scheduler heartbeat:** the scheduler records each minute that it ran.
  `GET /api/health/scheduler` returns 200 `{healthy: true, lastRunAt}`, or 503 once it hasn't run for 5
  minutes, so an uptime monitor can alert.
- **The scheduler has its own cache store.** It keeps the heartbeat and the "already sent" keys
  there, so the deploy's `cache:clear` no longer re-sends every reminder due in the next half hour.
- **The seed marker is persisted.** It lives in `storage/framework/.seeded`, and only one container
  seeds at a time. Before, every container recreate re-seeded in three containers at once, and
  duplicate-key errors appeared in the log.

### SCRUM-374: architecture
- **JSON or redirect, one rule:** `$request->wantsJsonResponse()` decides between JSON and a
  redirect for every success and failure response. The app's own refusals are also rendered
  centrally, wherever they're thrown. Before, a browser navigation could get JSON on success but a
  redirect on failure.
- **Scheduler sweeps moved:** the session and discussion reminder, start and failure sweeps moved
  out of `AppService` into their own actions.
- **Therapy presence channel:** the therapy's "who's online" channel is shared like the others.
- **Smaller notification payloads:** notifications about sessions, discussions and guardianship now
  carry small fixed summaries (id, name, times, status). They are the same for every recipient, so
  one person's view can't reach another.

### SCRUM-272: Paystack outages
- **Fail fast:** Paystack calls give up after 5 seconds to connect, or 15 in total.
- **Readable errors:** an unreachable Paystack now shows "Unable to … right now. Please try again
  shortly." Before, the request hung until a 502 or showed a generic error.
- **Account checks:** an account-number check that can't reach Paystack no longer says the number is
  wrong.

### SCRUM-248: stuck organisation charges
Every 15 minutes, any organisation-paid charge still "pending" after 30 minutes is checked with
Paystack and recorded as it says. This covers charges whose confirmation never arrived. One still
unresolved after a day is logged for someone to follow up.

### SCRUM-193
The admin users list shows each user's avatar beside their username.

### Also fixed (found in browser QA)
- **"Who's online" alerts:** the therapy page's "is already online" / "just came online" / "just
  went offline" alerts now show. They never did before.
- **Failure alerts survive redirects:** a failed page visit's alert is kept until a page shows it, so
  it isn't lost when "back" itself redirects.

## How to try it

### Test data
See `documentation/seeded-data.md`. Demo accounts use `password`; the super admin is `mr_robertamoah`
(`SUPER_PASSWORD` in `.env.docker`).

### 1. Countdown outside UTC
Set the browser's timezone to something other than UTC, for example Europe/Berlin. Then open a
therapy with a session starting soon. The "starts in" minutes match the real time left, and the
create-session form's default start is about 30 minutes from your local now.

### 2. Scheduler health
`curl http://localhost:8000/api/health/scheduler` returns 200 with a `lastRunAt` from the last minute.
Run `docker compose stop queue`; after 5 minutes it returns 503. Start the queue again with
`docker compose start queue`.

### 3. Paystack outage
With no network to Paystack (or a wrong `PAYSTACK_BASE_URL`), "pay now" shows the "try again" message
within a few seconds.

### 4. Admin users
As the super admin, open the admin users list. Users with a photo show it.

## Deployment notes
- **One migration:** it re-applies the `transaction_status_histories.source` enum to add `RECONCILE`.
- **New env vars, all optional:**
  - `PAYSTACK_CONNECT_TIMEOUT` (default 5) and `PAYSTACK_TIMEOUT` (default 15);
  - `ORG_STALE_CHARGE_RECONCILE_AFTER_MINUTES` (default 30) and `ORG_STALE_CHARGE_ALERT_AFTER_HOURS`
    (default 24);
  - `SCHEDULER_CACHE_STORE` (default `scheduler`).
- **New schedule entries:** `scheduler-heartbeat` (every minute) and `reconcile-stale-org-charges`
  (every 15 minutes).
- **After deploying:**
  - Point an uptime monitor at `/api/health/scheduler`.
  - Rebuild the frontend.
  - Restart the queue so the scheduler loads the new actions.
- **Existing Docker setups:** the first boot after this change looks for `storage/framework/.seeded`.
  Create it (`touch storage/framework/.seeded`) on an already-seeded environment, or the next
  container start seeds again.
- **Behaviour changes:**
  - A browser navigation to a success endpoint now gets a redirect, where it used to get JSON. That
    matches what failures already do.
  - Times outside UTC now display correctly.
  - Notification broadcast payloads are smaller. Nothing in the frontend read the removed fields.
