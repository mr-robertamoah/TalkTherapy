export default function useEnums() {
    const SessionStatusEnum = {
        pending: 'PENDING', // scheduled
        in_session_confirmation: 'IN_SESSION_CONFIRMATION', // when in person and waiting to end
        in_session: 'IN_SESSION', // when having the session
        failed: 'FAILED', // when scheduled but not held
        abandoned: 'ABANDONED', // held but ended before end time
        held: 'HELD',
        held_confirmation: 'HELD_CONFIRMATION',
    }

    return {
        SessionStatusEnum
    }
}