// TT-7.11d2/SCRUM-474: labels and pill tones for the admin recognition page (Admin/Recognition.vue). The rows come
// from GetRecognitionSuggestionsAction, the events from GetRecognitionEventsAction.

// Why a row may not (or no longer) be eligible -- shown as red/amber pills. A suggested row never has one; an
// "others" row (decided earlier) may.
export function recognitionAlerts(flags) {
    if (!flags) return []

    const alerts = []
    if (flags.deleted) alerts.push({ label: 'deleted', tone: 'red' })
    if (flags.suspended) alerts.push({ label: 'suspended', tone: 'red' })
    if (flags.openReport) alerts.push({ label: 'open report', tone: 'amber' })
    if (flags.verificationState && flags.verificationState !== 'verified') {
        alerts.push({ label: `verification ${flags.verificationState}`, tone: 'amber' })
    }

    return alerts
}

export function decisionPill(decision) {
    if (decision === 'approved') return { label: 'approved', tone: 'green' }
    if (decision === 'skipped') return { label: 'skipped', tone: 'gray' }

    return { label: 'not decided', tone: 'blue' }
}

// Consent only exists once a row is approved.
export function consentPill(consent) {
    return {
        pending: { label: 'consent pending', tone: 'amber' },
        accepted: { label: 'consent given', tone: 'green' },
        declined: { label: 'consent declined', tone: 'gray' },
        withdrawn: { label: 'consent withdrawn', tone: 'gray' },
    }[consent] ?? null
}

const EVENT_LABELS = {
    approved: 'approved',
    skipped: 'skipped',
    added: 'added by hand',
    consent_accepted: 'agreed to be featured',
    consent_declined: 'declined to be featured',
    consent_withdrawn: 'withdrew consent',
    consent_restored: 'gave consent again',
}

// "approved", or "skipped (was approved)" when a decision changed.
export function recognitionEventLabel(event) {
    const label = EVENT_LABELS[event?.event] ?? event?.event ?? ''

    return event?.from && ['approved', 'skipped', 'added'].includes(event.event) ? `${label} (was ${event.from})` : label
}

// The daily star caps, in the order the card lists them, with what each limits.
export const STAR_CAP_FIELDS = [
    { key: 'session', label: 'Held sessions', hint: 'stars a day from held sessions' },
    { key: 'help_chat', label: 'Help chats', hint: 'stars a day from urgent-help chats' },
    { key: 'discussion', label: 'Discussions', hint: 'stars a day from held discussions' },
    { key: 'post', label: 'Posts', hint: 'stars a day from published posts' },
    // Security review L3: 0 here stops every star that has a client, not just repeats.
    { key: 'session_per_client', label: 'Per client', hint: 'session and help-chat stars a day from one client (0 stops every session and help-chat star that has a client)' },
    { key: 'session_per_group', label: 'Per group', hint: 'group-session stars a day from one group' },
]
