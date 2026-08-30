export default function useEnums() {
    const RequestTypeEnum = {
        guardianship: 'GUARDIANSHIP',
        counsellor: 'COUNSELLOR_VERIFICATION_REQUEST',
        administrator: 'ADMINISTRATION_REQUEST',
        therapy: 'THERAPY_ASSISTANCE_REQUEST',
        discussion: 'THERAPY_DISCUSSION_REQUEST',
        groupTherapy: 'GROUP_THERAPY_ASSISTANCE_REQUEST',
        groupTherapyMembership: 'GROUP_THERAPY_MEMBERSHIP_REQUEST',
        organization: 'ORGANIZATION_VERIFICATION_REQUEST',
        organizationCounsellorInvite: 'ORGANIZATION_COUNSELLOR_INVITE_REQUEST',
        organizationCounsellorApplication: 'ORGANIZATION_COUNSELLOR_APPLICATION_REQUEST',
        organizationMemberInvite: 'ORGANIZATION_MEMBER_INVITE_REQUEST',
        organizationMemberApplication: 'ORGANIZATION_MEMBER_APPLICATION_REQUEST',
        organizationCounsellorCompensationChange: 'ORGANIZATION_COUNSELLOR_COMPENSATION_CHANGE_REQUEST',
    }
    const RequestStatusEnum = {
        accepted: 'ACCEPTED',
        pending: 'PENDING',
        rejected: 'REJECTED',
    }
    const SessionStatusEnum = {
        pending: 'PENDING',
        inSessionConfirmation: 'IN_SESSION_CONFIRMATION',
        inSession: 'IN_SESSION',
        failed: 'FAILED',
        abandoned: 'ABANDONED',
        held: 'HELD',
        heldConfirmation: 'HELD_CONFIRMATION',
    }
    const DiscussionStatusEnum = {
        pending: 'PENDING',
        inSession: 'IN_SESSION',
        failed: 'FAILED',
        abandoned: 'ABANDONED',
        held: 'HELD',
    }

    const PaymentTypeEnum = {
        free: 'FREE',
        paid: 'PAID'
    }

    const SessionTypeEnum = {
        once: 'ONCE',
        periodic: 'PERIODIC'
    }

    return {
        SessionStatusEnum, DiscussionStatusEnum, RequestStatusEnum, RequestTypeEnum,
        PaymentTypeEnum, SessionTypeEnum
    }
}