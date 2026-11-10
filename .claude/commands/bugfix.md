---
description: Fast, direct bug-fix flow — no ticket, no subagent ceremony required
---

Fix the bug described by the user. This is lightweight, direct work (see "How much process a task
needs" in `CLAUDE.md`) — do not create a Jira ticket or route through the full subagent sequence
unless the bug turns out to be bigger than described.

1. Reproduce the bug first. If you can't reproduce it, say so before proposing a fix.
2. Find the actual root cause — don't patch a symptom (e.g. don't silence an exception without
   understanding why it's thrown).
3. Fix it.
4. Add or update a test that would have caught this, where practical.
5. Run the relevant tests (`docker compose exec php php artisan test`) and verify the fix by
   actually exercising the affected flow, not just by reading the diff.
6. Summarize: what was broken, why, what changed.

If the bug is security-sensitive (auth, PII, anonymous-identity leaks) or touches production
data, pull in the `security-engineer` subagent even for an otherwise "small" fix.
