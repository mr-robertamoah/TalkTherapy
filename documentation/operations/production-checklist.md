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
  - The way back for an administrator (a super administrator too) who lost their phone and recovery codes is the
    server: `sudo -u www-data php artisan user:two-factor-reset <username|email>` (or `<id> --id`; asks first; `--force` for a
    non-interactive run such as SSM Run Command). The admin users list refuses administrators' accounts. Confirm who
    the person is outside the app first, and make sure more than one person has shell/SSM access before setting the
    date. A code rollback past SCRUM-34d removes this command -- keep another way to reset an administrator's.
  - A code rollback past SCRUM-34b would let accounts with two-factor in with the password alone -- roll back with
    the switch instead, and count them first (`users.two_factor_confirmed_at` not null).
  - `LOG_LEVEL` is `notice` or lower (`info`, `debug`): the lines a reset writes (`Two-factor sign-in reset by an
    administrator`, `Two-factor sign-in reset on the server`; monitoring.md) are the record of it, and a higher level
    drops them.
  - Queue workers restart on deploy (`deploy.sh` does): the security notices are queued, and an old worker doesn't know
    the newer kinds.

## The proxy

- [ ] It sets `X-Forwarded-Proto` (https), `X-Forwarded-For`, and `Host` / `X-Forwarded-Host` equal to
  `APP_URL`'s host.
- [ ] It **strips any `X-Forwarded-*` headers a client sent**.
- [ ] Without the forwarded scheme and host, signed links (email verification for users and counsellors)
  fail their signature check with a 403. The link is signed as `https://APP_URL/...`, but the request
  would look like `http://...`.

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
