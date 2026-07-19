# Release Workflow

1. Confirm all target features/fixes are merged to the release branch
   ↓
2. `qa-engineer` subagent — full regression pass, Pest suite green
   ↓
3. `security-engineer` subagent — final check on anything security-sensitive in this release
   ↓
4. `devops-engineer` subagent — run `/release` checklist (CI, Docker builds, migrations, env
   parity, rollback plan, monitoring)
   ↓
5. Produce the Release Checklist and Deployment Notes
   ↓
6. Wait for explicit human approval — never deploy or merge automatically

See `README.md` ("Deployment") — production deploys go through AWS Systems Manager Run Command,
not direct SSH.
