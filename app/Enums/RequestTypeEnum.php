<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum RequestTypeEnum: string
{
    use EnumTrait;
    case guardianship = 'GUARDIANSHIP';
    case counsellor = 'COUNSELLOR_VERIFICATION_REQUEST';
    case administrator = 'ADMINISTRATION_REQUEST';
    case discussion = 'THERAPY_DISCUSSION_REQUEST';
    case therapy = 'THERAPY_ASSISTANCE_REQUEST';
    case groupTherapy = 'GROUP_THERAPY_ASSISTANCE_REQUEST';
    // Distinct from `groupTherapy` above: that one is a counsellor requesting to help run a
    // group therapy; this one is a user requesting to join it as a member (SCRUM-72).
    case groupTherapyMembership = 'GROUP_THERAPY_MEMBERSHIP_REQUEST';
    case organization = 'ORGANIZATION_VERIFICATION_REQUEST';
    // An org admin inviting a counsellor to affiliate (SCRUM-120) -- distinct from
    // organizationCounsellorApplication below, which is the counsellor-initiated mirror.
    case organizationCounsellorInvite = 'ORGANIZATION_COUNSELLOR_INVITE_REQUEST';
    case organizationCounsellorApplication = 'ORGANIZATION_COUNSELLOR_APPLICATION_REQUEST';
    // Consumer-org membership flows (SCRUM-124) -- same invite/apply shape as the
    // organizationCounsellor* pair above, but for a User joining as a member, not a
    // Counsellor affiliating.
    case organizationMemberInvite = 'ORGANIZATION_MEMBER_INVITE_REQUEST';
    case organizationMemberApplication = 'ORGANIZATION_MEMBER_APPLICATION_REQUEST';
    // SCRUM-146 (TT-6.4c): a compensation-terms negotiation for an org-counsellor affiliation.
    // `for` is the OrganizationCounsellor affiliation itself (not the Organization directly).
    // `from`/`to` flip direction across rounds -- org proposes = from Organization to Counsellor;
    // a counter-offer (SCRUM-148) reverses it.
    case organizationCounsellorCompensationChange = 'ORGANIZATION_COUNSELLOR_COMPENSATION_CHANGE_REQUEST';
    // SCRUM-206 (TT-2.5a): a session day/time negotiation for a Therapy. `for` is the Therapy
    // itself. `from`/`to` alternate between the client User and the assigned Counsellor -- either
    // party may be the one who proposes (both are participants of the Therapy), with the other
    // side always the recipient, mirroring organizationCounsellorCompensationChange's from/to
    // flip on counter-offer (TT-2.5b).
    case sessionScheduleProposal = 'SESSION_SCHEDULE_PROPOSAL_REQUEST';
    // TT-7.7a/SCRUM-249: a client asking to be refunded for a Transaction. `for` is the
    // Transaction itself. `from` is the client User who asked; `to` is deliberately left null --
    // any platform admin may respond (EnsureUserCanRespondToRequestAction's own isAdmin() branch
    // already grants this without needing a specific `to` target, mirroring `administrator`'s own
    // never-populated `to` above). `data` carries the client's stated `reason`.
    case refund = 'REFUND_REQUEST';
    // TT-4.10c/SCRUM-292: created by EnsureDobChangeIsAllowedAction when a dob edit would flip a
    // user's minor/adult status in either direction AND that user has a qualifying relationship
    // on file (a Guardianship-as-ward row, or a Therapy/GroupTherapy client snapshot, TT-4.10a).
    // `for` is the User whose dob is changing; `from` is whoever made the edit (the user
    // themselves, or an admin); `to` is any ONE of the ward's active guardians (mirrors
    // GrantVideoConsentAction's own "any one guardian, no unanimity" precedent) when at least one
    // exists, else left null so any admin may respond (mirrors `refund`'s own null-`to` shape) --
    // never both an admin bypass AND an existing guardian at once. `data` carries `newDob` and
    // `priorDob` for audit/notification copy and for the (not-yet-built, TT-4.10d) approval
    // action's retroactive snapshot correction.
    case dobChange = 'DOB_CHANGE_REQUEST';
}
