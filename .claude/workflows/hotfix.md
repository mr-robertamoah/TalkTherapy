# Hotfix Workflow

For critical production issues only. Optimized for speed and safety, in that order — still no
skipped tests, but skip everything ceremonial.

Branch: `hotfix/<slug>` off `main` (production), since this is the one case that doesn't start
from `develop` — a bug live in production may not even exist in `develop`/`testing` yet, or may
need to reach production faster than the normal promotion chain. PR the hotfix into `main`, then
immediately back-merge `main` into `testing` and `develop` so the fix isn't lost on the next
normal promotion.

1. Reproduce/confirm the issue against production behavior (not just locally)
   ↓
2. Fix the smallest change that resolves it — resist the urge to also refactor nearby code
   ↓
3. Add a regression test if it can be done without materially slowing down the fix
   ↓
4. `security-engineer` subagent — quick pass if the hotfix touches auth, PII, or payments
   ↓
5. Verify the fix against the actual failure, then run the full Pest suite before shipping
   ↓
6. Deployment Notes: what broke, what changed, rollback plan if the fix itself misbehaves
   ↓
7. Ship, then retroactively file the Jira ticket and note it in
   `documentation/implementation_plan.md` — don't let a hotfix vanish from the record just
   because it skipped the upfront ticket step
