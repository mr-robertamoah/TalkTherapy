# TalkTherapy — Project Rules

TalkTherapy is a Laravel + Inertia/Vue platform connecting people seeking mental health support
with verified counsellors (see `README.md` for the product overview, and
`documentation/implementation_plan.md` for the current backlog).

## Stack & environment

- **Backend**: Laravel 12, Inertia Laravel v2, Sanctum, Laravel Reverb (websockets), Breeze.
- **Frontend**: Vue 3 + Inertia v2 (`@inertiajs/vue3`), Tailwind 3, Vite 6.
- **Tests**: Pest 3 (`pestphp/pest`, `pestphp/pest-plugin-laravel`).
- **DB**: MySQL 8.
- **Dev environment**: Docker Compose (`docker-compose.yml`) — services are `php` (app),
  `vite` (dev server, :5173), `web` (nginx, :8000), `db` (MySQL), `queue` (Supervisor), `reverb`
  (websockets, :8081), `mailpit` (SMTP catcher, :8025 UI). `.env.docker` is the env file actually
  used by containers (via `env_file:`, not a bind-mounted `.env`); the plain `.env` is for
  running things directly on the host, outside Docker, and the two are **not** kept in sync
  automatically — check both if something behaves differently in vs. out of Docker.
- See `.claude/memory.md` for architecture notes, known technical debt, and conventions that
  don't belong in this file because they change often.

### Common commands

```bash
docker compose up -d                                    # start the full stack
docker compose logs -f <service>                        # tail a service's logs
docker compose exec php php artisan test                 # run Pest tests
docker compose exec php ./vendor/bin/pint                # format PHP (Laravel Pint)
docker compose exec php php artisan migrate               # run migrations
docker compose exec vite npm run build                    # production frontend build
```
Emails sent in the Docker environment are caught by Mailpit, not actually delivered — view them
at http://localhost:8025.

## Branching & releases

Branch flow: `feature/*` / `bugfix/*` / `hotfix/*` → PR into `develop` → promote `develop` → `testing` →
promote `testing` → `main`. All four branches (`develop`, `testing`, `main`, plus whatever
`feature/bugfix/hotfix` branch you're on) already exist on `origin` — never create a competing
long-lived branch for these roles.

- New work (fixes or features) branches off `develop`, named `feature/<slug>`, `bugfix/<slug>`, or
  `hotfix/<slug>` depending on which of the three buckets in "How much process a task needs"
  applies.
- Every such branch's PR targets `develop`, never `testing` or `main` directly.
- Promotion from `develop` → `testing` → `main` happens as its own PR (`develop` into `testing`,
  then `testing` into `main`) once the accumulated changes are ready — this is what `/release`
  and the `devops-engineer` subagent check readiness for.
- Never merge your own PRs, at any stage of this chain.

## How much process a task needs

Not every change deserves the same ceremony. Match the weight to the work:

- **Bug fixes, chores, config/infra tweaks, small direct requests**: work like you would on any
  other task — investigate, fix, verify, done. No ticket, no subagent sequence required. Use the
  `/bugfix` command as a starting checklist if useful.
- **New user-facing features**: require a Jira ticket with acceptance criteria before
  implementation starts (see below), and go through the fuller review sequence in
  `.claude/workflows/feature-development.md` using the project's subagents (see below). Use the
  `/start-feature` command to kick this off.

If you're not sure which bucket something falls into, ask.

## Jira

This project uses Jira for feature-level work. Jira access is provided through the **claude.ai
Atlassian connector** (`mcp__claude_ai_Atlassian__*` tools), not a project-local MCP server —
authenticate once via `mcp__claude_ai_Atlassian__authenticate` when a Jira lookup is needed, and
prefer that over guessing ticket details. Never invent a ticket's contents; if the connector
isn't authenticated or a ticket is ambiguous, ask.

The connector has no sprint-management endpoints (no create/start/complete sprint, no move-to-
sprint) — see "Sprint tracking" in `.claude/agents/project-manager.md` for the manual `sprint-N`
label + priority substitute used until/unless that capability exists.

## Subagents

Project-specific subagents live in `.claude/agents/` and mirror the roles of a small engineering
team. Reach for them the way you'd loop in a specific teammate — not as a mandatory gate on every
task, except where "How much process a task needs" above says a feature requires the full
sequence:

- `product-owner` — turns a request/ticket into acceptance criteria, requirements, risks. Never
  writes code.
- `project-manager` — breaks approved requirements into milestones, dependencies, risks.
- `architect` — reviews design against the existing Laravel/Inertia architecture before or after
  implementation; rejects changes that add unnecessary coupling or duplication.
- `software-engineer` — implements. This is usually you, directly; delegate to the subagent when
  you want an isolated, independent implementation pass (e.g. inside a worktree) instead.
- `reviewer` — code review for maintainability, readability, simplicity, duplicated logic.
- `security-engineer` — auth, authorization, secrets, input validation, dependency risk.
- `qa-engineer` — validates acceptance criteria, runs the Pest suite, checks edge cases and
  regressions.
- `devops-engineer` — Docker/Compose changes, migrations, env vars, rollback, monitoring.

## Feature documentation

After completing a **feature** (not a small bug fix, unless asked), write
`documentation/features/<feature-slug>.md` describing what was built and exactly how to try it
out: steps to reproduce, any sample data/accounts needed, URLs, and what a successful result looks
like. Treat it as a hand-off doc for someone who wasn't in the conversation.

## General rules

- If requirements are unclear, ask questions — do not invent requirements.
- Prefer maintainability over cleverness; follow existing architecture and naming conventions.
- Keep commits small and focused.
- Run the formatter, linter, and test suite before calling a feature done; never skip or silently
  ignore a failing test.
- Never merge your own PRs.
- Security is mandatory, not a nice-to-have; performance should not regress.
- When wrapping up a feature (full-ceremony work), produce: Summary, Risks, Testing Performed,
  Deployment Notes — the QA/DevOps subagents' outputs cover most of this already.
