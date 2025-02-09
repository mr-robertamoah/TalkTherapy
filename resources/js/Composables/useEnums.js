export default function useEnums() {
    const RequestTypeEnum = {
        guardianship: 'GUARDIANSHIP',
        counsellor: 'COUNSELLOR_VERIFICATION_REQUEST',
        administrator: 'ADMINISTRATION_REQUEST',
        therapy: 'THERAPY_ASSISTANCE_REQUEST',
        discussion: 'THERAPY_DISCUSSION_REQUEST',
        groupTherapy: 'GROUP_THERAPY_ASSISTANCE_REQUEST',
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