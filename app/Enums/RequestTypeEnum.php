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
}
