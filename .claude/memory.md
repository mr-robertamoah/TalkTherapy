# Project Memory

Living notes for this repo — update as things change. `CLAUDE.md` covers rules/process; this file
covers facts.

## Project Architecture

Laravel monolith serving an Inertia.js v2 + Vue 3 SPA (no separate frontend/backend split — see
the discussion in `documentation/implementation_plan.md` on why full separation isn't recommended
right now). Domain model centers on `Therapy`/`GroupTherapy` (currently being unified in
`resources/js/Pages/UnifiedTherapy.vue`), each containing `Session`s, tied to a `TherapyCase` with
`TherapyTopic`/`TherapyTopicSession` structuring what's discussed. Real-time chat is `Discussion`
+ `Message`, broadcast over Laravel Reverb.

## Coding Standards

- PSR-style PHP, formatted with Laravel Pint (`docker compose exec php ./vendor/bin/pint`).
- Backend tests in Pest (`tests/`), run via `docker compose exec php php artisan test`.
- Vue SFCs under `resources/js/Pages` (route-level) and `resources/js/Components` (shared).
- Follow existing patterns before introducing new ones — this codebase already has parallel
  Therapy/GroupTherapy implementations from before the unification effort; don't add a third.

## Technology Stack

Laravel 12 · Inertia Laravel v2 · Vue 3 · Tailwind 3 · Vite 6 · Sanctum · Laravel Reverb ·
Laravel Breeze · Pest 3 · MySQL 8 · Docker Compose · Mailpit (dev-only SMTP catcher) · Mailgun
(real mail provider, configured but commented out in `.env.docker` while Mailpit is active).

## Repository Structure

- `app/Models`, `app/Http/Controllers`, `app/Services`, `app/Jobs` — standard Laravel layout.
- `resources/js/Pages` — Inertia page components; `resources/js/Components` — shared Vue
  components.
- `routes/web.php` (Inertia pages) and `routes/api.php` (JSON endpoints, e.g. `/api/counsellors/
  random`, `/api/posts`) coexist — most of the app is server-rendered-props via Inertia, not a
  pure API.
- `documentation/` — `project_plan.md` and `sprint_plan.md` are the original backlog (kept as
  history); `implementation_plan.md` is the current, reconciled source of truth; `features/` holds
  per-feature try-it-out docs.
- `docker/` — `php/Dockerfile` + `entrypoint.sh`, `nginx/default.conf`, `queue/supervisord.conf`,
  `reverb/supervisord.conf` + `entrypoint.sh`.

## Common Commands

See "Common commands" in `CLAUDE.md`.

## Naming Conventions

- Migrations: `create_<table>_table.php` / `<verb>_<detail>_<table>.php` for alterations.
- Controllers named after the primary resource (`TherapyController`, `SessionController`), not
  by HTTP verb or page.

## Known Technical Debt

- `UnifiedTherapy.vue` still has an unresolved frontend piece from TT-1.1/SCRUM-14:
  `TherapyComponent.vue`'s `getMessageTo()`/`getMessageFrom()` (lines ~639-685) assume a single
  `therapy.counsellor`/`therapy.user` field that `GroupTherapyResource` never returns (it returns
  `addedby` + a `counsellors` array instead) — sending a message during an active Group Therapy
  session still throws a JS exception. The backend access-control half of TT-1.1 (GroupTherapy's
  `isParticipant`/`isUser`/`isCounsellor` etc. being hardcoded stubs, plus a separate
  `User::isGuardianOfAUserFor()` stub that made every Group Therapy effectively public) was fixed
  in SCRUM-14/SCRUM-63. The `UpdateIndividualTherapyFormModal.vue`/`UpdateGroupTherapyFormModal.vue`
  validation-logic duplication identified in the same scoping pass is also still outstanding.
- Two independent `migrate --force && db:seed --force` runs (in `docker/php/entrypoint.sh` and
  `docker/reverb/entrypoint.sh`) share one MySQL volume but track "already seeded" with a
  container-local marker file that doesn't survive container recreation — expect a harmless
  duplicate-key seeder error in logs on most restarts. Not a real bug worth fixing urgently, but
  don't mistake it for a live failure when debugging something else.
- `.env` (host) and `.env.docker` (container, via `env_file:`) are two separate files that drift
  — always check which one actually applies before trusting a config value.
- `phpunit.xml` routes test runs to an isolated sqlite DB via matching `<env>`/`<server>` entries
  (SCRUM-60) — `<env>` alone doesn't work here because Laravel's env resolution reads `$_SERVER`
  before `$_ENV`/`getenv()`, and PHPUnit's `<env force="true">` never touches `$_SERVER`. If this
  ever needs re-verifying: confirmed (SCRUM-62) that `php artisan config:cache` is never run
  anywhere in this project's local Docker entrypoint, CI workflows, or the production deploy
  script (`.github/workflows/main.yml` uses `cache:clear`, not `config:cache`) — so the "cached
  config bakes in the real DB connection and phpunit.xml's overrides have nothing left to
  override" failure mode is currently only theoretical. `tests/TestCase.php::setUp()` also throws
  loudly if the resolved DB connection isn't `sqlite`, as an independent safety net regardless of
  which mechanism would otherwise have failed.

## Current Sprint Goals

See `documentation/implementation_plan.md` → "Reconciled Sprint Roadmap". Sprint 1 (Platform
Stability) is the current priority; nothing else should ship ahead of it.

## Frequently Used Jira Labels

Not yet established — define these once the team starts filing tickets through the Atlassian
connector, and record the convention here.

## Deployment Environments

- **Local dev**: Docker Compose, as described in `CLAUDE.md`.
- **Production**: AWS EC2, continuously deployed via AWS Systems Manager Run Command (no open SSH
  port) — see `README.md` → "Deployment".
