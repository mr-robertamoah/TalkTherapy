---
description: Review the current branch using the reviewer, security-engineer, and qa-engineer subagents
---

Review the current branch's diff against `main` for TalkTherapy.

Run in parallel using the Agent tool:
- `reviewer` — maintainability, readability, simplicity, duplicated logic
- `security-engineer` — auth, authorization, secrets, input validation, dependency risk
- `qa-engineer` — test coverage, edge cases, regression risk

Synthesize their findings into one report:

```
## Bugs
## Risks
## Security Issues
## Test Coverage
## Final Recommendation
```

Do not modify code — this command is review-only. If findings warrant fixes, say so and wait to
be asked.
