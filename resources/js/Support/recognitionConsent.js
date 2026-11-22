// TT-7.11f/SCRUM-476: a counsellor's own consent controls for their recognitions (OwnRecognitionsCard on their profile)
// and the public badge's wording. The rows come from OwnCounsellorRecognitionResource. A pending one is answered
// through its consent request (requests.respond: accepted | rejected); afterwards the counsellor withdraws or opts back
// in through counsellor.recognitions.consent (withdraw | restore) -- CounsellorRecognitionConsentEnum's transitions.

export const BADGE_PREFIX = 'Counsellor of the month'

export function badgeLabel(month) {
    return month ? `${BADGE_PREFIX}, ${month}` : BADGE_PREFIX
}

const STATES = {
    pending: 'Waiting for your answer. Signed-in people can already see it.',
    accepted: 'Featured on Home and on your profile.',
    declined: 'You chose not to be featured. Only you see it.',
    withdrawn: 'You stopped being featured. Only you see it.',
}

export function consentStateText(consent) {
    return STATES[consent] ?? ''
}

// What the counsellor can do now. Each: { key, label, kind: 'respond' | 'consent', value, primary }.
export function consentActions(recognition) {
    if (!recognition) return []

    switch (recognition.consent) {
        case 'pending':
            // Answered through its request; without one (it was closed) they can still say no.
            return recognition.consentRequestId
                ? [
                    { key: 'accept', label: 'Agree to be featured', kind: 'respond', value: 'accepted', primary: true },
                    { key: 'decline', label: 'Don\'t feature me', kind: 'respond', value: 'rejected', primary: false },
                ]
                : [{ key: 'withdraw', label: 'Don\'t feature me', kind: 'consent', value: 'withdraw', primary: false }]
        case 'accepted':
            return [{ key: 'withdraw', label: 'Stop featuring me', kind: 'consent', value: 'withdraw', primary: false }]
        case 'declined':
        case 'withdrawn':
            return [{ key: 'restore', label: 'Feature me', kind: 'consent', value: 'restore', primary: true }]
        default:
            return []
    }
}

export function consentDoneMessage(action) {
    return {
        accept: 'Thank you. You are now featured.',
        decline: 'Done. You won\'t be featured for that month.',
        withdraw: 'Done. You are no longer featured for that month.',
        restore: 'Done. You are featured again for that month.',
    }[action?.key] ?? 'Saved.'
}
