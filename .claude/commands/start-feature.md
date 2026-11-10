---
description: Kick off full-ceremony feature work from a Jira issue
---

Read the supplied Jira issue using the Atlassian connector (`mcp__claude_ai_Atlassian__*` —
authenticate first if needed). If no issue key is given, ask for one; do not invent ticket
contents.

Act in the following order, using the Agent tool with these subagents:
1. `product-owner` — acceptance criteria, requirements, risks
2. `project-manager` — milestones, dependencies, slot into `documentation/implementation_plan.md`
3. `architect` — design assessment against the existing Laravel/Inertia architecture

Present to the user:
```
## Requirements
## Questions
## Risks
## Technical Plan
```

Wait for explicit approval before writing any code. Once approved, implement directly (or via the
`software-engineer` subagent for an isolated pass), then follow
`.claude/workflows/feature-development.md` through to completion, including the
`documentation/features/<feature-slug>.md` write-up required by `CLAUDE.md`.
