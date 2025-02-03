export default function useConsts() {
    const RequestTypes = {
        guardianship: 'GUARDIANSHIP',
        counsellor: 'COUNSELLOR_VERIFICATION_REQUEST',
        administrator: 'ADMINISTRATION_REQUEST',
        therapy: 'THERAPY_ASSISTANCE_REQUEST',
        discussion: 'THERAPY_DISCUSSION_REQUEST',
        groupTherapy: 'GROUP_THERAPY_ASSISTANCE_REQUEST',
    }
    const RequestStatuses = {
        accepted: 'ACCEPTED',
        pending: 'PENDING',
        rejected: 'REJECTED',
    }
    const SessionStatuses = {
        pending: 'PENDING',
        inSessionConfirmation: 'IN_SESSION_CONFIRMATION',
        inSession: 'IN_SESSION',
        failed: 'FAILED',
        abandoned: 'ABANDONED',
        held: 'HELD',
        heldConfirmation: 'HELD_CONFIRMATION',
    }
    const DiscussionStatuses = {
        pending: 'PENDING',
        inSession: 'IN_SESSION',
        failed: 'FAILED',
        abandoned: 'ABANDONED',
        held: 'HELD',
    }

    return {
        RequestTypes, RequestStatuses, SessionStatuses, DiscussionStatuses
    }
}