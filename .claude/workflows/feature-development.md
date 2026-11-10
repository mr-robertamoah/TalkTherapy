# Feature Workflow

Applies to new user-facing features (see "How much process a task needs" in `CLAUDE.md`). Bug
fixes and small changes skip this entirely — use `.claude/workflows/bug-fix.md` instead.
Branch: `feature/<slug>` off `develop`, PR back into `develop` (see "Branching & releases").

1. Read Jira Ticket (via the Atlassian connector)
   ↓
2. `product-owner` subagent — acceptance criteria, requirements, risks
   ↓
3. `project-manager` subagent — milestones, dependencies, fit against `documentation/implementation_plan.md`
   ↓
4. `architect` subagent — design assessment
   ↓
5. **Wait for user approval before writing code**
   ↓
6. Implementation (directly, or via `software-engineer` subagent)
   ↓
7. If the feature has a frontend/UI component: quick Playwright smoke-check (main session) —
   navigate the new/changed page(s) as the relevant seeded role, confirm it renders with no
   console errors and the core interaction works, before spending review-agent cycles on it. If
   the feature needs seed data that doesn't exist yet, add it to `DatabaseSeeder.php` (or a
   dedicated seeder it calls) now rather than working around it by hand — see
   `.claude/workflows/playwright-qa.md`. Skip this step for backend-only work.
   ↓
8. `reviewer` subagent
   ↓
9. `security-engineer` subagent
   ↓
10. `qa-engineer` subagent — Pest suite plus, for features with a UI component, its own fuller
    Playwright-driven walkthrough of the acceptance criteria's golden path and edge cases (see
    `.claude/workflows/playwright-qa.md`)
    ↓
11. `devops-engineer` subagent
    ↓
12. If the feature needs data that doesn't already exist in `DatabaseSeeder.php`, add it there
    (or a dedicated seeder it calls) — don't leave manual setup as a prerequisite to trying the
    feature out
    ↓
13. Write `documentation/features/<feature-slug>.md` — what was built, how to try it out,
    including a "Test data" section naming the exact seeded account(s)/record(s) to use (see
    `documentation/seeded-data.md`)
    ↓
14. Pull Request Summary (Summary / Risks / Testing Performed / Deployment Notes)
    ↓
15. Ready for Human Review — never merge automatically
