---
description: Release readiness check using the devops-engineer subagent
---

Use the `devops-engineer` subagent to verify release readiness for TalkTherapy.

Verify:
- CI passed
- Docker images build cleanly (`docker compose build`)
- Infrastructure changes (docker-compose.yml, docker/, nginx config) are accounted for
- Database migrations are present, backward-compatible, and reversible
- `.env.docker` vs `.env` parity for anything the release depends on
- Mail is pointed at a real provider, not Mailpit, if this release targets anything beyond local
  dev (see the commented Mailgun config in `.env.docker`)
- Rollback strategy is documented
- Monitoring/alerts are in place for anything new

Produce a Release Checklist. Never merge or deploy without explicit user confirmation.
