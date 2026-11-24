// TT-7.10b/SCRUM-468: commission-free periods are set in UTC (the admin form says so), so their dates are shown in
// UTC too, labelled GMT -- the same instant for every counsellor, organisation and administrator, whatever their
// browser's time zone.

const utcFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'UTC',
})

export function formatUtc(value) {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''

    return `${utcFormat.format(date)} GMT`
}

// The one-line notice for a period running or coming up ({ startsAt, endsAt, scope }, ISO strings); null without one.
// TT-7.11e/SCRUM-475: scope 'personal' is the counsellor's own period (only they see it); anything else is platform-wide.
export function commissionFreeNoticeText(notice) {
    if (!notice?.startsAt) return null

    const payments = notice.scope === 'personal' ? 'your payments' : 'payments made'

    return notice.endsAt
        ? `No platform commission on ${payments} between ${formatUtc(notice.startsAt)} and ${formatUtc(notice.endsAt)}`
        : `No platform commission on ${payments} from ${formatUtc(notice.startsAt)}`
}

// TT-7.11e2/SCRUM-477: 'YYYY-MM-DDTHH:mm' in UTC, for a datetime-local input labelled UTC/GMT (the server reads a value
// with no offset as UTC).
export function utcInputValue(date) {
    const pad = (value) => String(value).padStart(2, '0')

    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`
}

// A period's status (PlatformFeeWaiver::status()) as a pill -- on Payouts and the recognition page. Cancelled: ended
// early with "End now".
export function waiverStatusPill(status) {
    return {
        upcoming: { label: 'upcoming', tone: 'blue' },
        active: { label: 'active', tone: 'green' },
        ended: { label: 'ended', tone: 'gray' },
        cancelled: { label: 'cancelled', tone: 'amber' },
    }[status] ?? { label: status ?? 'unknown', tone: 'gray' }
}
