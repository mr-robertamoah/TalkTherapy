// TT-7.11d2/SCRUM-474: labels and pill tones for the admin recognition page (Admin/Recognition.vue). The rows come
// from GetRecognitionSuggestionsAction, the events from GetRecognitionEventsAction.
import { utcInputValue } from './commissionFree.js'

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

// TT-7.11e2/SCRUM-477: the optional fee waiver offered when approving -- prefilled with the next calendar month in UTC
// (the 1st of next month 00:00 to the 1st of the month after), as `datetime-local` values the server reads as UTC, and
// a reason naming the recognition's month ("Counsellor of the month, October 2026"). `now` is a Date (tests pass one).
export function waiverPrefill(recognitionMonthLabel, now = new Date()) {
    const year = now.getUTCFullYear()
    const month = now.getUTCMonth() // 0-11; Date.UTC rolls month 12 into January of the next year.

    return {
        starts_at: utcInputValue(new Date(Date.UTC(year, month + 1, 1))),
        ends_at: utcInputValue(new Date(Date.UTC(year, month + 2, 1))),
        reason: `Counsellor of the month, ${recognitionMonthLabel}`,
    }
}

// A datetime-local value read as UTC (as the server does); null when empty or malformed.
function parseUtcInput(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(value)) return null
    const date = new Date(`${value}Z`)

    return Number.isNaN(date.getTime()) ? null : date
}

// The server allows a start up to a minute back (PlatformFeeWaiver::BACKDATE_LEEWAY_SECONDS).
const BACKDATE_LEEWAY_MS = 60 * 1000

// TT-7.11e2/SCRUM-477: the waiver option checked before the approval is posted, so an approval isn't saved with a
// period that can only be refused: both dates and a reason, the end after the start, the start not in the past (the
// server checks all of it again, and the overlap and eligibility only it knows). Field => message; {} when it may go,
// or when the option is off.
export function waiverOptionErrors(option, reasonMax, now = new Date()) {
    if (!option?.enabled) return {}

    const errors = {}
    const startsAt = parseUtcInput(option.starts_at)
    const endsAt = parseUtcInput(option.ends_at)
    const reason = (option.reason ?? '').trim()

    if (!startsAt) errors.starts_at = 'Enter a start date and time.'
    else if (startsAt.getTime() < now.getTime() - BACKDATE_LEEWAY_MS) errors.starts_at = 'The start cannot be in the past.'

    if (!endsAt) errors.ends_at = 'Enter an end date and time.'
    else if (startsAt && endsAt.getTime() <= startsAt.getTime()) errors.ends_at = 'The end must be after the start.'
    else if (endsAt.getTime() <= now.getTime()) errors.ends_at = 'The end must be in the future.'

    if (!reason) errors.reason = 'Enter a reason.'
    else if (reason.length > reasonMax) errors.reason = `The reason can be up to ${reasonMax} characters.`

    return errors
}
