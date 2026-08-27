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
}
