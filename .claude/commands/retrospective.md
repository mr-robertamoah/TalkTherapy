---
description: Run a short retrospective on recently completed work
---

Look at recent git history (`git log`, `git diff` against the point this work started) and the
conversation so far, then produce a short retrospective:

```
## What shipped
## What went well
## What was harder than expected (and why — root cause, not just "it was tricky")
## Action items
```

Action items should be concrete enough to act on later: a specific `CLAUDE.md`/`.claude/agents/`
adjustment, a specific known-bug entry worth adding to `documentation/implementation_plan.md`, or
a specific process change — not vague sentiment like "communicate better."

Do not modify code as part of this command; if an action item implies a code or doc change, name
it and ask before making it.
