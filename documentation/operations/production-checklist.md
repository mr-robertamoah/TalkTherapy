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
- [ ] **`TRUSTED_PROXIES`** lists the proxy's own addresses, comma-separated, **never `*`** (SCRUM-395/400).
  Without it every visitor looks like the proxy, which gives:
  - 10 sign-ups an hour for the whole site;
  - one urgent-help per-address limit for everyone;
  - `http` instead of `https` on incoming requests.
- [ ] **`TRUSTED_HOSTS`** is empty, or lists bare hostnames that never serve pages, such as a health-check
  name. A second public domain (for example `www.`) should be **301-redirected at the proxy**, not listed:
  pages served on it would post to `APP_URL`'s host, and the session cookie wouldn't follow.

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
