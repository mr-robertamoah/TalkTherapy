# Playwright MCP for QA and Frontend UI Verification

When and how to use the Playwright MCP browser tools (`mcp__playwright__*`) against the running
TalkTherapy stack. This complements, not replaces, the Pest suite: Pest verifies backend logic and
HTTP-layer contracts; Playwright verifies what actually renders and behaves in a real browser,
which Pest structurally cannot see (real Vue/Inertia client-side behavior, real DOM output, real
multi-tab/multi-session interaction, visual layout, console errors).

## When this fires autonomously

**Full-ceremony feature work only** (see CLAUDE.md's "How much process a task needs"), at two
checkpoints in `.claude/workflows/feature-development.md`:

1. **Quick smoke-check, main session** — right after implementation, before handing off to
   `reviewer`/`security-engineer`. Navigate to the new/changed page(s) as the relevant seeded
   role, confirm the page renders, there are no console errors, and the core interaction works.
   This is a fast sanity check to catch obvious breakage before spending review-agent cycles on
   something that doesn't even load — not a substitute for the fuller walkthrough below.
2. **Full acceptance-criteria walkthrough, `qa-engineer` subagent** — its own pass, after
   implementation is otherwise complete. Drives the browser through the golden path and edge
   cases from the acceptance criteria, using seeded accounts, and folds findings into its
   existing Test Report / Failed Cases output.

**Bugfixes/chores that happen to touch a `.vue` file or a frontend-rendering route**: available,
not mandatory. Use judgment — reach for it the same way you'd reach for any other verification
step, per CLAUDE.md's general "use the feature in a browser before reporting a UI change done"
guidance, but it's not an automatic gate for bugfix-tier work.

**Anything else**: only when explicitly asked.

## Prerequisites

- The dev stack must be running: `docker compose up -d` (services: `php`, `vite`, `web` on
  `:8000`, `db`, `queue`, `reverb` on `:8081`, `mailpit` on `:8025`).
- Navigate against `http://localhost:8000` (the `web`/nginx service) — that's the real
  full-stack path a user actually hits, not the raw Vite dev server port.
- Log in using seeded accounts from `documentation/seeded-data.md` rather than creating
  throwaway accounts by hand. If a scenario needs data that doesn't exist in the seeder, that's
  itself a QA finding — the seeder should be updated (per the existing feature-development
  workflow step), not worked around with manual setup.
- Emails (verification, notifications) land in Mailpit (`http://localhost:8025`), not a real
  inbox — check there if a flow's assertion depends on an email being sent.

## Best use cases

Reach for Playwright specifically where Pest's HTTP/JSON-assertion layer can't tell you the whole
story:

1. **New page/route smoke test.** After adding a new Inertia page or route, load it as each
   relevant role (client/counsellor/org admin/platform admin) and confirm it renders without
   console errors and the key elements from the acceptance criteria are actually visible.

2. **Multi-step, cross-role flows.** Anything that spans more than one actor's UI in sequence —
   e.g. an org admin inviting a counsellor, then switching to the counsellor's session to
   accept/reject, then back to the admin to set compensation terms. Pest tests each side's HTTP
   contract in isolation; Playwright is what actually proves the handoff works end to end.

3. **Anonymity/masking correctness in the rendered UI.** This codebase has real precedent for
   masking bugs slipping through backend-only tests (`RequestResource`'s requester/creator
   identity masking, anonymous group-therapy joins). A Pest assertion on a JSON fixture doesn't
   prove the identity isn't leaking through a tooltip, a hover state, or a client-side computed
   value the backend never sees. Load the same page as both the masked party and an outsider and
   visually/DOM-diff what each actually sees.

4. **Cross-role visibility / authorization boundaries, rendered.** Two browser contexts (e.g. two
   logged-in sessions) confirming one user's private data doesn't render into another's page —
   a real-browser complement to the backend authorization tests, not a replacement for them.

5. **Client-side vs. server-side validation/error-message consistency.** Confirm a validation
   error the backend returns (per the SCRUM-93/94 exception-message work) actually surfaces to
   the user in the UI, rather than being swallowed or replaced by a generic Inertia error page.

6. **Visual regression after a Vue component change.** Screenshot before/after a component
   refactor or style change to catch unintended layout breakage that no Pest test could ever see.

7. **Payment/Paystack checkout flow.** Confirm the "pay" button actually navigates to a checkout
   URL, and that the callback route renders the right success/failure state — the actual browser
   redirect behavior, not just `InitiatePaystackChargeAction`'s return value.

8. **Real-time/Reverb broadcast UI behavior.** `implementation_plan.md` (TT-1.2) already flags a
   known duplicate-broadcast risk. Two browser contexts (sender + receiver) sending a message and
   confirming it appears exactly once for the receiver is something Pest cannot exercise at all,
   since Reverb's actual websocket delivery isn't part of the HTTP test cycle.

9. **File upload UI wiring.** Profile pictures, testimonial attachments, verification documents —
   confirm the actual `<input type="file">` interaction produces a successful upload, since
   Pest's faked storage doesn't prove the frontend's upload widget is wired to the right field.

## What NOT to use it for

- Anything already fully covered by a Pest HTTP/feature test with no rendering-specific risk
  (e.g. a pure validation-rule change with no UI-visible behavior difference). Don't duplicate
  coverage for its own sake.
- Backend business logic, authorization decisions, or data correctness — that's what the Pest
  suite and the `security-engineer`/`reviewer` subagents are for. Playwright confirms the UI
  reflects those decisions correctly, it doesn't re-verify the decisions themselves.

## Findings and cleanup

- A real bug surfaced via Playwright is a QA finding like any other — record it in the
  `qa-engineer` subagent's Test Report / Failed Cases sections (or, for the main-session smoke
  check, just fix it or flag it before proceeding to review). Don't silently note it and move on.
- Close browser contexts/pages when a check is done so no orphaned browser processes linger
  between tickets.
