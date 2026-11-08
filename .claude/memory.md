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

- TT-1.1/SCRUM-14's frontend piece is resolved: `TherapyComponent.vue`'s `getMessageTo()`/
  `getMessageFrom()` now branch on `props.therapyType === 'group'` and read `addedby`/`counsellors`
  instead of assuming a single `therapy.counsellor`/`therapy.user` field (fixed in SCRUM-66, PR #18).
  The backend access-control half (GroupTherapy's `isParticipant`/`isUser`/`isCounsellor` stubs,
  plus `User::isGuardianOfAUserFor()`) was fixed in SCRUM-14/SCRUM-63. The
  `UpdateIndividualTherapyFormModal.vue`/`UpdateGroupTherapyFormModal.vue` validation-logic
  duplication identified in the same scoping pass was also resolved (SCRUM-67 — extracted into
  `resources/js/Composables/useTherapyFormValidation.js`). TT-1.1 has no known outstanding pieces.
- Only the php container migrates and seeds (`docker/php/entrypoint.sh`, under a `flock`), and seeds
  once: the marker is `storage/framework/.seeded` on the bind mount (SCRUM-373). It also sets storage
  permissions (`docker/php/storage-permissions.sh`, SCRUM-376/378). That entrypoint is copied into
  the image at build time, so changing it needs `docker compose build php queue reverb` before a
  recreate.
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
- Laravel Reverb 1.10 (composer.lock pins v1.10.0) can't close a user's existing websocket
  subscriptions from the server (SCRUM-386): `terminate_connections` fails with a 500 when the
  user's first channel is a private one, which is always true here because every signed-in user
  subscribes to `users.{id}` first. So someone who leaves a group, or loses access to a therapy,
  keeps the channels they already joined until they disconnect or reload. Exposure is limited to
  the presence roster and the id-only "a message arrived" pings. **Revisit trigger:** when Reverb is upgraded past 1.10,
  re-test `terminate_connections` for a user whose first channel is private and, if it works,
  close a leaver's subscriptions from the server.
- The Inertia client and server are a major version apart (SCRUM-387): the client is
  `@inertiajs/vue3` 1.x (package.json) while the server is `inertiajs/inertia-laravel` 2.x
  (composer.json). Client-side v2 features -- deferred props (`Inertia::defer`), polling, prefetch,
  infinite scroll -- are unavailable, so the admin sidebar's badges are fetched from their own JSON
  route (`GET /administrator/badges`, `useAdminBadges.js`) instead of a deferred prop. CLAUDE.md
  says "Inertia v2", which is only true of the server. **Revisit trigger:** once the client is
  upgraded to `@inertiajs/vue3` 2.x, move the badges to `Inertia::defer` on the admin pages and drop
  the route.

- **Counsellor verification is current, not historical (SCRUM-31, 2026-11-06).**
  `Counsellor::isVerified()` means verified, not ended (`verification_expires_at`) and not revoked
  (`verification_revoked_at`); `wasEverVerified()` is "has `verified_at`". Every gate on new work
  uses `isVerified()`; taking on a client goes through `EnsureCounsellorMayTakeOnClientsAction`.
  Never write `verified_at` directly to verify someone -- `ApplyCounsellorVerificationAction` sets
  the end date and records it. Tests and seeds that create a verified counsellor by hand need a
  `verification_expires_at` too (the factory sets one; `unverified()`, `lapsed()` and
  `verificationEndingIn()` states exist).

- **Discovery lists and cards (SCRUM-113/114/389, 2026-11-07).** Home/Public lists rank in SQL
  (`App\Support\PreferenceRanking`, `PreferenceMatch`) with a `?seed=` that the client sends back for
  stable paging; never sort them in PHP after paginating. A card's button comes from the server
  (`ResolveTherapyCardStateAction` -> `viewer.action`); don't re-derive it in Vue. An assistance
  request's direction is `Request::isAssistanceOffer()` / `direction`, never guessed from party types.
  Counsellor viewers are never matched on a client's religions (special-category data).

- **Admin search and the counsellor dashboard (SCRUM-390/391/393, 2026-11-07).** Every admin list
  searches through `App\Support\AdminSearch` (`term()`, `person()`, `withoutInvolved()`) and the
  `AdminSearchBox` + `useAdminSearch` frontend; a new admin list should too, and a row a viewer sees only
  as "involved" must be excluded by the same rule its resource masks by (one method, not a copy).
  Counsellor names go through `Counsellor::whereName`. "Free counsellor place" in a query is
  `GroupTherapy::whereCanTakeCounsellor` (kept in step with `canTakeCounsellor()`). The dashboard's
  `Request::scopeAwaitingCounsellor` lists by type -- add a new counsellor-answerable request type
  there too. Cards share `TherapyCardFrame` (kind by colour) and `PreferenceMatchMark`.

- **Group helpers and public lists (SCRUM-392, 2026-11-07).** Use `GroupTherapy::creatorUser()`,
  `isCreatedBy()`, `isFull()`, `hasFreeCounsellorPlace()`/`canTakeCounsellor()` -- never re-derive them
  inline. The Home/Public lists use the `publicForOutsider(?User)` model scope; it hides the viewer's
  own therapies, so it is never an access check. Counsellor offers to help are capped per day
  (`EnsureCounsellorOfferCapAction`). There is one join modal (`JoinGroupTherapyModal`, incl.
  invitation links). Resources send raw `status` plus `statusLabel`.

- **Quick help chat (SCRUM-33, 2026-11-08).** "I need help now" is mounted at the app root
  (`resources/js/app.js` → `HelpNowRoot`), not in a layout. A help chat is a `HelpRequest` plus a
  private, free Therapy + Session flagged `is_help_chat`: **every new therapy/session list, count,
  calendar or stat must call `withoutHelpChats()`**. Help routes are `help.*` and are open to suspended
  accounts (the middleware allowlist) -- keep new help routes under that name and check access in the
  action. Countries come from `resources/data/countries.json` (`App\Support\Countries`,
  `CountrySelect`); regenerate with `npm run countries:generate`, never hand-edit.
  Admin queue (TT-4.4b): who may take or answer a request is decided only by the `Counsellor` scopes
  (`ableToTakeUrgentHelp`, `availableForUrgentHelp`, `mayHelpWith`) and
  `RingAvailableCounsellorsAction::{mayAnswer, ringingFor}`; don't re-derive it. An admin
  "involved" in a request (`HelpRequest::involvesAdmin`) never sees it, and every status change
  calls `HelpBroadcast::queueChanged`.
  After the chat (TT-4.4c): "invite to a regular therapy" is only an invitation; the person goes
  through the normal therapy form/request (`HelpRequest::canInviteToTherapy`). Help chats are deleted a
  year after ending (`PruneHelpChatTranscriptsAction`, held while `HelpRequest::hasOpenReport`). Any
  new place that stores help-chat content (attachments, notes) must also be cleaned up there.
- **Safety flags (SCRUM-32, 2026-11-08).** Behind `SAFETY_FLAGS_ENABLED` (off; `safety_flags` route
  middleware, `features.safetyFlags` prop). Who an admin is "involved" with in a therapy/group is
  `TherapyTrait::involvesInOversight` (shared with reports) -- don't copy it. Group members are referred to
  by `App\Support\GroupMemberKeys` keys, never user ids. Admin pages/routes use `SafetyFlag::public_id`,
  never the sequential id, and the live ping goes only to uninvolved admins' `users.{id}` channels.
  Handling (TT-4.3b/c): "still needs someone" is `SafetyFlag::scopeWhereUnresolved` (incl. withdrawn after
  acknowledgement); notices go through `SendSafetyFlagNoticeAction` only; any new free-text column on a
  flag must be added to `SafetyFlag::FREE_TEXT` so anonymisation clears it.

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
