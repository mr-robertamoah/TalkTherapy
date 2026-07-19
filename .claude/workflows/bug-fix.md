# Bug Fix Workflow

Lightweight by design — see "How much process a task needs" in `CLAUDE.md`. No Jira ticket, no
subagent ceremony required by default.

1. Reproduce the bug
   ↓
2. Find root cause (not just the symptom)
   ↓
3. Fix
   ↓
4. Add/update a test where practical
   ↓
5. Verify by exercising the actual affected flow, not just reading the diff
   ↓
6. Summarize what was broken, why, and what changed

Escalate to the full feature workflow (or at least pull in `security-engineer`) only if the fix
turns out to touch auth, PII, anonymous-identity privacy, or production data — or if it's bigger
than it looked.
