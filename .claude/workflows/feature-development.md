# Feature Workflow

Applies to new user-facing features (see "How much process a task needs" in `CLAUDE.md`). Bug
fixes and small changes skip this entirely — use `.claude/workflows/bug-fix.md` instead.

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
11. Write `documentation/features/<feature-slug>.md` — what was built, how to try it out
    ↓
12. Pull Request Summary (Summary / Risks / Testing Performed / Deployment Notes)
    ↓
13. Ready for Human Review — never merge automatically
