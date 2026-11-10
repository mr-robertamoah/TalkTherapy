# SCRUM-164 (TT-6.7): Member self-apply via shareable organization link

Part of the TT-6.6/TT-6.7 backend-enablement sub-tickets (see `documentation/decision-log.md`'s
2026-08-29 TT-6.5/TT-6.6 restructuring entry). Backend-only — no frontend UI yet; TT-6.5c2/TT-6.5a
(later sub-tickets) build the UI that generates/displays these links. Additive to the public
organization directory (TT-6.6c) — both are being built per the user's 2026-08-29 decision, not a
replacement for each other.

## What was built

- A new `LinkTypeEnum::organizationSelfApply` case, reusing this codebase's existing generic
  `Link` model/flow (already used for guardianship/discussion/therapy-counsellor links) rather
  than bespoke plumbing.
- **Generating a link** (org admin only): `POST /api/links` with
  `type=ORGANIZATION_SELF_APPLY`, `forType=Organization`, `forId=<organization id>` — the existing
  generic link-creation endpoint. A new `EnsureUserCanCreateOrganizationSelfApplyLinkAction`
  requires the caller to administer the target organization; a no-op for every other link type.
- **Using a link**: `GET /links/{uuid}` (the existing generic link-use endpoint) — a new
  `PerformOrganizationSelfApplyLinkAction` locks the link, checks it's still active, calls the
  *existing* `OrganizationMemberRequestService::applyAsMember()` unchanged (same eligibility
  checks as the direct `POST /organizations/{id}/member-applications` self-apply path: organization
  verified, `is_consumer`, `self_apply_enabled`, no existing pending request), then deactivates the
  link so it can't be replayed (SCRUM-101 convention).
- Shareable, not single-recipient (`to=null`) — any authenticated user except the link's creator
  can use it, same as the existing guardianship/discussion link types.

## Not yet built

The directory browse endpoint (TT-6.6c, separate, already built) and any UI (TT-6.5c2, TT-6.5a for
link generation).

## How to try it

No seeded data yet. Via `php artisan tinker` or a REST client:
1. As an org admin: `POST /api/links` with the params above → get back a `Link` resource with a
   `url` (`www.talktherapy.tech/links/{uuid}`).
2. As a different, prospective member: visit `GET /links/{uuid}` → redirected home, a pending
   `organizationMemberApplication` Request now exists for that organization (same as if they'd
   called the direct apply endpoint) — the org's admin sees it via the existing `GET /requests`
   endpoint (SCRUM-162) or the org-scoped `member-applications` flow.
3. Reusing the same link a second time → "This link is no longer active."

## Testing performed

- Full backend suite: 687 passed (up from 683 before the security fixes, 672 at develop HEAD).
  Whole-file Pint clean on every touched file.
- `reviewer` subagent: one required change applied before merge — `GetLinksDTO::$for` hadn't been
  widened to accept `Organization` alongside `CreateLinkDTO::$for`, which would have thrown an
  uncaught `TypeError` (surfaced as a 500) the first time an org admin looked up an already-
  generated link via the existing generic `GET /api/links?forType=Organization&forId=...` endpoint.
  Also flagged (and fixed) the same `createMultipleLinks()` gap security review caught
  independently, and a migration comment citing a nonexistent file (fixed).
- `security-engineer` subagent: two findings fixed before merge — (1) High: `createMultipleLinks()`
  bypassed the new authorization guard entirely (not reachable over HTTP today due to an unrelated
  pre-existing routing mismatch, fixed proactively anyway); (2) Low-Medium: a fresh 422-vs-403
  organization-existence enumeration oracle this ticket introduced, closed by reordering the new
  guard to run first. Full reasoning in `documentation/decision-log.md`.
- New tests: `tests/Unit/OrganizationSelfApplyLinkTest.php` (authorization guard, its no-op
  behavior for other link types, the enumeration-oracle fix, `createMultipleLinks`'s guard,
  `getLinks` with an Organization `for`, successful self-apply, replay prevention, eligibility
  reuse), `tests/Feature/OrganizationSelfApplyLinkControllerTest.php` (real-route creation
  authorization, real-route self-apply, creator-can't-use-own-link).

## Files changed

- `app/Enums/LinkTypeEnum.php` — new `organizationSelfApply` case
- `database/migrations/2026_08_29_120000_add_organization_self_apply_to_links_type_enum.php` (new)
- `app/DTOs/CreateLinkDTO.php`, `GetLinksDTO.php` — widened `$for` to include `Organization`
- `app/Actions/Link/EnsureUserCanCreateOrganizationSelfApplyLinkAction.php`,
  `PerformOrganizationSelfApplyLinkAction.php` (both new)
- `app/Actions/Link/PerformLinkAction.php` — new dispatch branch
- `app/Services/LinkService.php` — wired the new guard into `createLink()` and
  `createMultipleLinks()`
