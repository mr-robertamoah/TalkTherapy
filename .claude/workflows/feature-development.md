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
7. `reviewer` subagent
   ↓
8. `security-engineer` subagent
   ↓
9. `qa-engineer` subagent
   ↓
10. `devops-engineer` subagent
    ↓
11. If the feature needs data that doesn't already exist in `DatabaseSeeder.php`, add it there
    (or a dedicated seeder it calls) — don't leave manual setup as a prerequisite to trying the
    feature out
    ↓
12. Write `documentation/features/<feature-slug>.md` — what was built, how to try it out,
    including a "Test data" section naming the exact seeded account(s)/record(s) to use (see
    `documentation/seeded-data.md`)
    ↓
13. Pull Request Summary (Summary / Risks / Testing Performed / Deployment Notes)
    ↓
14. Ready for Human Review — never merge automatically
