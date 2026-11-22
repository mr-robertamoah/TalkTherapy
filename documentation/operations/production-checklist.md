# Production behind a proxy: checklist

Production will sit behind a load balancer, CDN or reverse proxy (the user, 2026-11-09). Several safeguards
depend on the settings below. If they're wrong, things fail quietly: sign-ups are limited site-wide,
everyone shares one urgent-help limit, email verification links return 403, or health checks fail.
Check every item before going live.

## Settings

- [ ] **`APP_ENV=production`** (or another name that isn't `local` or `testing`). Laravel turns the
  trusted-host check off for `local` and `testing`, so a deployed environment must never use either.
- [ ] **`APP_URL`** is the real public URL, over **https**, with no trailing path. Every absolute link (emails,
  redirects, notification links) is built on it (SCRUM-397). If it's missing, the default
  `http://127.0.0.1:8000` makes every real request a 400.
- [ ] **Mail goes to Mailgun, not Mailpit** (SCRUM-413): in the deployed `.env`, the Mailgun `MAIL_HOST` / `MAIL_PORT` /
  `MAIL_USERNAME` / `MAIL_PASSWORD` lines (commented in `.env.docker.example`) replace the `mailpit` ones, and
  `MAIL_FROM_ADDRESS` is a real sender on that domain. Otherwise verification and password-reset emails never arrive.
- [ ] **`TRUSTED_PROXIES`** lists the proxy's own addresses or CIDR ranges, comma-separated (SCRUM-395/400/138). For a
  CDN such as Cloudflare, that is its published ranges.
  - Wildcards (`*`), `REMOTE_ADDR`, `PRIVATE_SUBNETS`, ranges wider than /8 (IPv4) or /29 (IPv6) and IPv4-mapped IPv6
    ranges are ignored, so they never trust everyone. Each one ignored logs `TRUSTED_PROXIES entries ignored.` at boot.
  - Only the visitor's address, port and scheme are taken from the proxy (`X-Forwarded-For/-Port/-Proto`), not
    `X-Forwarded-Host` or `-Prefix`.
  - On Laravel Forge or Vapor hosts (`*.on-forge.com`, `*.on-vapor.com`) or Laravel Cloud, Laravel itself trusts every
    proxy when this is empty. Set it explicitly there.
  - Check after deploying: sign in from two networks and confirm the admin's user list (or the logs) shows different
    addresses, not the proxy's.
  Without it every visitor looks like the proxy, which gives:
  - 10 sign-ups an hour for the whole site;
  - one urgent-help per-address limit for everyone;
  - `http` instead of `https` on incoming requests.
- [ ] **`TRUSTED_HOSTS`** is empty, or lists bare hostnames that never serve pages, such as a health-check
  name. A second public domain (for example `www.`) should be **301-redirected at the proxy**, not listed:
  pages served on it would post to `APP_URL`'s host, and the session cookie wouldn't follow.

- [ ] **Websockets (Reverb)** accept connections only from `APP_URL`'s host plus `TRUSTED_HOSTS` (SCRUM-403), or
  from `REVERB_ALLOWED_ORIGINS` if set. If the pages are served from another host than `APP_URL`'s, real-time
  updates (chat, the bell, urgent help rings) silently stop. Restart the `reverb` container after changing
  any of these.

- [ ] **Web push for urgent help (SCRUM-399), if switched on (`HELP_PUSH_ENABLED=true`):**
  - `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` generated once with `php artisan push:vapid-keys`, stored as secrets,
    **backed up and never rotated casually** -- new keys silently end every counsellor's device subscription.
  - `VAPID_SUBJECT` a real `mailto:` the push services can reach.
  - **Whatever supervises the production queue worker** (the EC2 supervisor/systemd unit -- not in this repo;
    `docker/queue/supervisord.conf` only covers Docker) runs `queue:work --queue=urgent,default`. A worker on the
    default queue alone never sends a push: the jobs sit in `urgent` without any error.
  - The PHP on the host has `curl`, `openssl` and `mbstring` (`php -m`) -- minishlink/web-push requires them, and a
    missing one fails `composer install` mid-deploy, leaving the site in maintenance mode. `bcmath` or `gmp` makes
    sending faster.
  - After changing `HELP_PUSH_ENABLED` or the keys in the env file: `php artisan config:clear` and
    `php artisan queue:restart` -- the running worker keeps the config it started with.
  - Rotating `APP_KEY` goes through `APP_PREVIOUS_KEYS`: devices are stored encrypted, and without the old key every
    push job fails to decrypt them.
  - nginx serves `/sw.js` with `Cache-Control: no-cache` and `/manifest.webmanifest` as `application/manifest+json`
    (docker/nginx/default.conf).
  - Outbound HTTPS from the queue workers to the push services (Google FCM, Mozilla, Apple, Microsoft) is allowed, and
    `HELP_PUSH_ENDPOINT_HOSTS` (the allowlist a device's address must match) left at its default unless a browser's
    push service is missing from it.
  - With queue workers on more than one host, the cache store is shared (redis or database, not `file`): who was pushed
    for each request is kept there, so the withdrawal reaches them all. A `cache:clear` loses it -- rings already sent
    then stay on devices until they expire or are tapped.
- [ ] **Two-factor sign-in (SCRUM-34):**
  - Each account's authenticator secret is encrypted with `APP_KEY`, and its recovery codes are hashed with it. Keep
    the old key in `APP_PREVIOUS_KEYS` for good (nothing re-encrypts them); losing it means every account with
    two-factor on needs it reset. Changing `APP_KEY` also signs everyone out (each session's check is keyed with it).
  - The host PHP has `iconv` (bacon/bacon-qr-code; built into the usual packages).
  - Setup is shown by default. Check the production `.env` doesn't still say `TWO_FACTOR_ENABLED=false` (an earlier
    example suggested it) unless it should stay hidden; after changing it, `php artisan config:clear`. Off hides
    setting it up only: accounts that have it are still asked for the code and can still manage it.
  - Requiring it for administrators (SCRUM-34d) is off until `TWO_FACTOR_ADMINS_REQUIRED_FROM` is set to a date --
    `YYYY-MM-DD` only, starting at 00:00 in `APP_TIMEZONE` (UTC by default); anything else (including `false`) is off.
    Order:
    1. Deploy with it unset and `TWO_FACTOR_ENABLED` on.
    2. Set a date some weeks out; `php artisan config:clear`. Administrators without it now see a reminder.
    3. Before the date, count who's left and chase them:
       `php artisan tinker --execute='echo App\Models\User::whereHas("administrator")->whereNull("two_factor_confirmed_at")->count();'`.
       Every super administrator should have it on and have kept their recovery codes.
    4. From the date, administrators without it are sent to their profile to set it up (they stay signed in; admin
       pages, actions and admin powers wait). To stop requiring it: unset it (or move the date) and `config:clear`.
  - **Resets wait (SCRUM-424)**: a reset from the admin users list or `user:two-factor-reset` is *scheduled* and
    applies after `TWO_FACTOR_RESET_WAIT_HOURS` (default 48) -- and only once the warning email has gone out -- unless
    the person cancels it. The scheduler applies them (`apply-due-two-factor-resets`, every minute): with the
    scheduler, the queue or mail down nothing applies (late, never early). Leave the variable unset unless there's a
    reason; after changing it, `config:clear` (Docker: `docker compose up -d --force-recreate php queue`); it affects
    resets asked for afterwards.
  - The way back for an administrator (a super administrator too) who lost their phone and recovery codes is the
    server: `sudo -u www-data php artisan user:two-factor-reset <username|email> --reason=<ticket>` (or `<id> --id`;
    asks first; `--force` skips the question for a non-interactive run such as SSM Run Command, except for an account
    without a verified email). It schedules the reset; `--cancel` stops one. `--now` applies at once -- break-glass
    (say, the last super administrator locked out): it needs an interactive terminal (SSM Session Manager, not Run
    Command), a `--reason`, and refuses `--force`. Confirm who the person is first
    (`documentation/operations/two-factor-reset-identity-check.md`), and make sure more than one person has interactive
    Session Manager access before setting the date. A code rollback past SCRUM-34d removes this command -- keep another
    way to reset an administrator's.
  - Rolling back SCRUM-424: roll back the code, not the migration -- old code ignores `two_factor_resets`, so waiting
    resets just never apply (and resets are immediate again). Dropping the table loses the waiting resets and their
    record; dump it first (`mysqldump <db> two_factor_resets`). To stop all waiting resets in an emergency:
    `UPDATE two_factor_resets SET cancelled_at = NOW(), cancel_reason = 'server' WHERE applied_at IS NULL AND cancelled_at IS NULL;`
  - A code rollback past SCRUM-34b would let accounts with two-factor in with the password alone -- roll back with
    the switch instead, and count them first (`users.two_factor_confirmed_at` not null).
  - `LOG_LEVEL` is `notice` or lower (`info`, `debug`): the reset lines (`… reset requested by an administrator`,
    `… requested on the server`, `… applied`, `… cancelled`, `… cancelled on the server`, and `Two-factor sign-in reset
    on the server` for `--now`; monitoring.md) are, with the `two_factor_resets` table, the record of resets, and a
    higher level drops them.
  - Queue workers restart on deploy (`deploy.sh` does): the security notices are queued, and an old worker doesn't know
    the newer kinds.
  - SCRUM-433, `EMAIL_STOP_RESTORE_DAYS` (optional, 7-90, default 30): "This wasn't me" on an account with no email
    puts its remembered address back only if it was remembered that recently. After changing it, `config:clear` (or
    in Docker `docker compose up -d --force-recreate php queue`). Before deploying, count the accounts that will go
    to support instead (remembered before SCRUM-433, so no date) and brief support:
    `SELECT COUNT(*) FROM users WHERE email IS NULL AND last_verified_email IS NOT NULL AND last_verified_email_at IS NULL;`
  - SCRUM-36/38, the private journal: `APP_KEY` encrypts every journal entry and nothing re-encrypts them. Rotate it
    only with the old key kept in `APP_PREVIOUS_KEYS` (for good), and back the key up apart from the database -- a
    database backup without it recovers no journal (the page and the data copy then fail with `DecryptException`).
  - SCRUM-38, PDF export (dompdf): the host's PHP has `dom` and `mbstring` (`php -m`; `gd` recommended) -- without them
    `composer install` fails mid-deploy and the site stays in maintenance mode. php-fpm `memory_limit` at least 384M,
    and nginx `fastcgi_read_timeout` / FPM `request_terminate_timeout` at least 90s for `POST /profile/journal/export`
    (capped at about 7 seconds of work, but a slow CPU stretches it). With more than one app host, set `CACHE_STORE`
    to redis or database so the one-export-at-a-time lock is shared.
  - SCRUM-40, notification choices: applied by an auto-discovered listener (`ApplyNotificationChoices`). `deploy.sh`
    doesn't cache events; if `event:cache` or `optimize` is ever run, run it on every deploy (a stale cache drops the
    listener and switched-off notifications go out anyway), and `event:clear` before rolling back.
  - Rolling back SCRUM-36/40: roll back the code, not the migrations -- the old code ignores `journal_entries` and
    `users.notification_channels`. Dropping `journal_entries` deletes people's private journals for good; dump it first.
  - SCRUM-437, counsellor notes encrypted at rest: the deploy that brings `2026_12_05_100000` rewrites every
    `session_notes` / `message_notes` row (soft-deleted too) under maintenance mode, at about 300-500 rows a second
    (measured). Before promoting to `main`: count them
    (`SELECT (SELECT COUNT(*) FROM session_notes) + (SELECT COUNT(*) FROM message_notes);`) -- over about 500,000, run
    the migration by hand in a maintenance window (`artisan down` / `migrate --force` / `up` over SSM) rather than in
    the 60-minute CI deploy; free disk for one more copy of each table; dump the two tables first
    (`mysqldump --single-transaction <db> session_notes message_notes | gzip > notes-pre-437.sql.gz` -- plaintext
    clinical notes: keep it encrypted and delete it once the deploy is confirmed; older backups also still hold them).
    If it stops partway the site stays in maintenance mode: fix the cause and re-run -- rows already encrypted are left
    alone. Never rotate `APP_KEY` in the same deploy. From here on `APP_KEY` (with `APP_PREVIOUS_KEYS` kept for good
    when it rotates) is the only way to read any counsellor note or check-in answer.
  - Rolling back SCRUM-437: never by deploying an older commit (the notes stay encrypted and the old code shows
    ciphertext). By hand, with the new code still checked out: `artisan down`, then roll back only that migration
    (`migrate:rollback --path=database/migrations/2026_12_05_100000_encrypt_session_and_message_notes_content.php --force`
    -- it refuses if any note doesn't decrypt with the current key), then check out the previous SHA. Never a plain
    `migrate:rollback`: the batch may also hold `outcome_check_ins` and the journal's tables.
  - SCRUM-37, outcome check-ins: off in production unless `CHECK_INS_ENABLED=true` (every route a 404, the panel
    hidden). Switch it on only after the clinical and legal wording is approved, then `config:clear` (Docker:
    `docker compose up -d --force-recreate php queue`). The self-harm attention notice goes on the `urgent` queue, so
    that worker must run. Before switching it off again, check no attention answer is left unacknowledged:
    `SELECT COUNT(*) FROM outcome_check_ins WHERE needs_attention = 1 AND attention_acknowledged_at IS NULL;`
  - SCRUM-434, support: someone writing from an account's long-remembered address (warnings there now say only to
    contact support) is **not** proof they own the account -- that mailbox may have passed to someone else. Check who
    they are another way (`two-factor-reset-identity-check.md`; the account's identity-check records) before setting
    an address or a password on it.
  - Rolling back SCRUM-433: roll back the code, not the migration -- older code ignores `last_verified_email_at`. The
    SCRUM-431 drop (`2026_12_02_100000`) and this add (`2026_12_03_100000`) reach production in the same deploy, so
    they share a migrate batch: never a plain `migrate:rollback` -- `--step=1` undoes only this one (and loses every
    date, so no address is put back afterwards; dump `id, last_verified_email_at` first).
  - `LOG_LEVEL` `notice` or lower also keeps `Data copy downloaded` (SCRUM-432) and the stop line's `email_restored` /
    `has_email` (a warning).
  - SCRUM-432, streamed downloads: `POST /profile/your-data/download` writes the file as it goes
    (`X-Accel-Buffering: no`). The production nginx must honour that header (no `fastcgi_ignore_headers
    X-Accel-Buffering`), any proxy, load balancer or CDN must not buffer or cache that route, and php-fpm needs no
    `request_terminate_timeout` shorter than a large export takes. Smoke-test one download after deploying (`jq .
    file.json`). To switch the download off without a code rollback: `location = /profile/your-data/download { return 503; }`.
- [ ] **Google sign-in (SCRUM-112; schema and switch from SCRUM-452):**
  - `GOOGLE_SIGN_IN_ENABLED` stays `false` (its routes are a 404) until both SCRUM-454 (sign-in) and SCRUM-455
    (linking) are merged and deployed. After changing it, `php artisan config:clear` (Docker:
    `docker compose up -d --force-recreate php queue`).
  - A Google Cloud OAuth client (type "Web application") for production, with its consent screen published (in
    "Testing" mode it admits only listed test users, at most 100). Only openid, email and profile are asked for, so no
    Google security review is needed; adding a logo starts brand verification.
    `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` live only in the deployed env file or secret store, never in git.
  - `GOOGLE_REDIRECT_URI` is registered on that client **exactly** -- character for character, scheme, host, path and
    any trailing slash -- and built on the https `APP_URL`. Google refuses any mismatch, and an `http` one means the
    proxy settings are wrong. Each environment that tries Google sign-in (testing, local) needs its own redirect URI on
    the client, or its own client.
  - SCRUM-455: `GOOGLE_CONNECT_REDIRECT_URI` -- connecting Google from the profile comes back to its own callback,
    `{APP_URL}/profile/google/callback` -- is registered on the **same** client as a **second** authorised redirect
    URI, exactly as above. While it's empty the profile offers no "Connect Google" (its routes are a 404); signing in
    with Google still works.
  - `TRUSTED_PROXIES` is correct (above): behind a proxy without it, the request looks like `http` and the callback URL
    Laravel builds won't match the registered one.
  - `users.password` is nullable from `2026_12_09_100000`: an account made with Google has no password, and every
    password check refuses it. The old code works with this schema, so rolling back the code needs no schema rollback.
    To undo the schema anyway: set `GOOGLE_SIGN_IN_ENABLED=false`, run `php artisan migrate:rollback
    --path=database/migrations/2026_12_09_100000_add_google_sign_in_to_users_table.php` on the **current** code
    (accounts without a password get a random one and reset it by email), then deploy the old code.
  - SCRUM-456: new usernames must be ASCII (`alpha_dash:ascii`); existing ones are left alone. **Run once around this
    release** (not a deploy gate): an administrator lists the existing non-ASCII usernames, to look for look-alikes of
    other accounts (MySQL 8):
    `SELECT id, username, created_at FROM users WHERE username REGEXP '[^ -~]' OR CHAR_LENGTH(username) <> LENGTH(username);`
    Don't shorten it to the `REGEXP` alone: under some collations look-alikes such as the Kelvin sign (K) or the long s
    (ſ) can match the ASCII range, and only the `CHAR_LENGTH` check (multi-byte characters) catches them. Any that
    impersonate someone are dealt with by hand (there is no username change in the app).
  - SCRUM-456: **review before deploying** the accounts whose stored date of birth is in the future -- with this release
    they count as age 0 (a minor), where the old maths counted the gap as an age (often an adult):
    `SELECT id, username FROM users WHERE dob > NOW();`
  - SCRUM-456 migrations: `2026_12_10_100000` adds `blocked_google_identities` (an HMAC of the Google id, keyed with
    `APP_KEY`, for accounts an administrator closed), `2026_12_10_110000` indexes `users.pending_email` and
    `users.last_verified_email`, `2026_12_10_120000` adds `two_factor_resets.google_only_confirmed_at`. Roll them back on
    the **current** code first (`php artisan migrate:rollback --step=3`), then deploy the old code -- the old code
    doesn't have these files. **Dropping `blocked_google_identities` lifts every block for good** (the Google ids aren't
    kept anywhere else): once any administrator has closed an account connected to Google, treat that table as
    do-not-drop and roll back only the other two (`--path=` each file).
  - SCRUM-456: rotating `APP_KEY` keeps blocks only while the old key is in `APP_PREVIOUS_KEYS`; each blocked Google
    account is re-hashed under the new key the next time it tries, so leave the old key there for a while after a
    rotation.
  - SCRUM-456 runbook, lifting one block (support, after a review), with the Google id (`sub`) from the person:
    `docker compose exec -u www-data -e HOME=/tmp php php artisan tinker --execute="echo App\Models\BlockedGoogleIdentity::lift('<sub>');"`
    (deletes its hash under the current and every previous key; prints how many rows went).
  - SCRUM-456 (optional): Laravel pads a failed sign-in to `auth.timebox_duration`, in **microseconds** (`200000` = 200
    ms, the default; not set in `config/auth.php`). Measure `Hash::check` (bcrypt) on the production hardware; if it
    is close to or above 200 ms, set `timebox_duration` in `config/auth.php` above it, so a password-less account's
    quick refusal can't be told apart from a wrong password.
- [ ] **`VIDEO_PROVIDER`** is `daily` or `chime`, never `fake` (SCRUM-458). The fake connects nobody and enforces
  nothing; outside `local`/`testing` it throws on every video join, so a call would never start.
- [ ] **Video reconnect (SCRUM-459):**
  - Rolling back SCRUM-459: roll back the code, not the migration -- older code ignores
    `video_session_participants.removed_at` (nullable, no backfill). To drop it anyway: on the **current** code,
    `php artisan down`, then
    `php artisan migrate:rollback --path=database/migrations/2026_12_11_100000_add_removed_at_to_video_session_participants_table.php --force`,
    then deploy the old code. The ADD and DROP are `ALGORITHM=INSTANT` on MySQL >= 8.0.29 (INPLACE, non-blocking,
    below that).
- [ ] **Video against a real provider (SCRUM-317):** before launch, work through
  `documentation/operations/video-real-provider-checklist.md` on the provider being deployed.
- [ ] **Platform fee waivers (SCRUM-467):**
  - The deploy that brings `2026_12_12_100001` and `2026_12_12_100002` adds a foreign key to `counsellor_earnings` and
    `organization_invoice_lines`, which MySQL 8 does as a full table copy (writes blocked; measured ~12 s for 250,000
    rows combined) under maintenance mode. Before promoting to `main`, count them
    (`SELECT (SELECT COUNT(*) FROM counsellor_earnings) + (SELECT COUNT(*) FROM organization_invoice_lines);`) and
    make sure there is free disk for one more copy of each table.
  - `PAYMENTS_FEE_WAIVER_CHECKOUT_GRACE_MINUTES` (default 30): how long after a client starts checkout a payment still
    takes the waiver in force at the start, once that waiver has ended. Leave the default unless support sees genuine
    slow checkouts losing a waiver.
  - Rolling back SCRUM-467: roll back the code, not the migrations -- older code ignores the waiver table and columns.
    Dropping them loses which fees were waived and why (`fee_amount` stays 0): dump
    `platform_fee_waivers counsellor_earnings organization_invoice_lines` first, then on the **current** code
    (`php artisan down`) roll back `2026_12_12_100002`, `100001`, `100000` with `--path=` each, never a bare rollback.
    A waiver that any earning or invoice line refers to can't be deleted (restrict) -- end it instead.
- [ ] **Counsellor star foundation (SCRUM-470):**
  - The deploy that brings `2026_12_13_100000` adds two nullable columns (`source`, `award_key`) and two indexes to
    `stars` -- the unique `(starred_type, starred_id, award_key)` and the period-count
    `(starred_type, created_at, starred_id, source)` -- under maintenance mode. On MySQL 8.0.29+ the columns are
    instant; on older 8.0 they rebuild the table (online, but needs free disk for one more copy). The index builds are
    online at about 40 s per million rows (dev machine). Before promoting to `main`, check `SELECT VERSION();` and
    `SELECT COUNT(*) FROM stars;`.
  - `RECOGNITION_PER_MONTH` (default 5) and `RECOGNITION_STARS_COUNT_FROM` (default unset) don't need setting at
    release. The floor must be `YYYY-MM-DD` only; an invalid value is ignored with a warning in the log. After changing
    either, run `php artisan config:clear` and `php artisan queue:restart`.
  - Rolling back SCRUM-470: roll back the code, not the migration -- older code ignores the new columns. Rolling the
    migration back deletes every star's source and award key for good (new-rule stars become indistinguishable from
    legacy ones), so dump `stars` first, then on the **current** code roll back `2026_12_13_100000` with `--path=`,
    never a bare rollback.
- [ ] **Counsellor recognition backend (SCRUM-473):**
  - The deploy brings `2026_12_14_200000` (`counsellor_recognitions`), `2026_12_14_200001` (its events) and
    `2026_12_14_200002`, which re-applies the `requests.type` enum with `COUNSELLOR_RECOGNITION_REQUEST` (a table
    rebuild on `requests`, like every earlier enum addition -- check `SELECT COUNT(*) FROM requests;` first).
  - Rolling back SCRUM-473: prefer rolling back the code -- older code ignores the new tables and request type. Rolling
    the migrations back **deletes every featuring consent request** (`2026_12_14_200002`) and **every recognition and
    its history** (`2026_12_14_200000`/`..01`) for good, so dump `requests`, `counsellor_recognitions` and
    `counsellor_recognition_events` first, then roll back the three files together with `--path=` (newest first), never
    a bare rollback. Rolling back only `..200002` leaves approved recognitions with no consent (pending is cleared).
- [ ] **Per-counsellor commission-free periods (SCRUM-475):**
  - The deploy brings `2026_12_16_100000`, a new `platform_fee_waiver_events` table (empty, no data migrated -- periods
    created before it have no `created` event). No env vars.
  - Rolling back SCRUM-475: prefer rolling back the code -- older code ignores the events table, and the per-counsellor
    periods already made keep applying to fees (`PlatformFeePolicy` already honoured them); end any that shouldn't run
    on from the database (`ends_at = NOW(), ended_by_id = <a super admin id>`) before or after. Rolling the migration
    back **deletes the whole history of every period, including the reasons of deleted ones**, so dump
    `platform_fee_waiver_events` first, then roll back `2026_12_16_100000` with `--path=`, never a bare rollback
    (verified on MySQL 8: fresh + seed, rollback, migrate).

## The proxy

- [ ] It sets `X-Forwarded-Proto` (https), `X-Forwarded-For`, and `Host` / `X-Forwarded-Host` equal to
  `APP_URL`'s host.
- [ ] It **strips any `X-Forwarded-*` headers a client sent**.
- [ ] Without the forwarded scheme and host, signed links (email verification for users and counsellors, confirming a new account email -- SCRUM-427, the
  two-factor reset cancel link -- SCRUM-424)
  fail their signature check with a 403. The link is signed as `https://APP_URL/...`, but the request
  would look like `http://...`.

## Route caching (optional, SCRUM-426)

- [ ] Route names are unique since SCRUM-426 (CI checks it), so `php artisan route:cache` works. `scripts/deploy.sh`
  doesn't run it. If routes are cached, re-run `route:cache` after every deploy that touches `routes/` (a stale cache
  serves the old routes), and run `route:clear` before rolling back to a commit older than SCRUM-426 (those can't be
  cached). If it's added to `deploy.sh`, make it best-effort: a failed cache must not leave the site in maintenance
  mode -- `artisan route:cache || { artisan route:clear || true; }`. Ziggy (`@routes`) reads the routes on each
  request, so the frontend build needs nothing.

## Health checks and monitors

- [ ] A load-balancer or uptime probe calling `/up` or `/api/health/scheduler` sends the **public Host
  header**. A probe calling the app by IP address or an internal name gets a 400 (SCRUM-397). The
  alternative is to answer the probe at nginx or the load balancer. The Docker Compose healthcheck is
  file-based and unaffected.

## After changing any of these

- [ ] Recreate the containers so they read the new env (`docker compose up -d --force-recreate php queue`),
  and run `php artisan queue:restart`, because workers keep the config they booted with. If config is cached,
  run `php artisan config:cache` too.

## Smoke test after going live

- A link in an email (verification, password reset) opens on the public https host.
- Verifying an email address works (no 403).
- `curl -H 'Host: evil.example' https://<public host>/up` returns 400.
- The per-address limits key on real client addresses: the `Registration refused` warnings carry different
  `address` values, not one shared value. See `monitoring.md`.
