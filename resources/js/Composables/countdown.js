import { differenceInSeconds, format, parseISO } from 'date-fns'

// SCRUM-369: minutes from `now` until a session's or discussion's start and end -- the one copy of
// this maths (useTherapyState's header timer and DiscussionModal).
//
// SCRUM-372: rounded so a whole minute is never claimed early -- "to start" rounds up, "to end"
// rounds down.
//
// SCRUM-375: the times are true UTC (the forms send toISOString() of the time picked, and the
// server stores UTC), so no timezone adjustment. The old `+ getTimezoneOffset()` was off by the
// viewer's UTC offset -- invisible in Ghana (UTC+0), hours out anywhere else.
export function minutesUntilWindow(startTime, endTime, now = new Date()) {
    return {
        beforeStart: Math.ceil(differenceInSeconds(parseISO(startTime), now) / 60),
        beforeEnd: Math.floor(differenceInSeconds(parseISO(endTime), now) / 60),
    }
}

// SCRUM-375: a value for <input type="datetime-local">, which is LOCAL time. toISOString() gives
// UTC, so its slice put a default/min time off by the viewer's UTC offset.
export function toLocalInputValue(date) {
    return format(typeof date === 'string' ? parseISO(date) : date, "yyyy-MM-dd'T'HH:mm")
}

// SCRUM-377: a form's default time, at least `minutes` from now, rounded UP to the next 5-minute mark.
// The input drops seconds, so "now + 30" truncated to the minute was already under the server's
// 30-minute rule the moment it was shown, and submitting the default was refused. The extra minute and
// the rounding leave a short margin for filling in the form.
const FIVE_MINUTES = 5 * 60 * 1000

export function defaultFormTime(minutes, now = new Date()) {
    const earliest = now.getTime() + (minutes + 1) * 60 * 1000

    return toLocalInputValue(new Date(Math.ceil(earliest / FIVE_MINUTES) * FIVE_MINUTES))
}
