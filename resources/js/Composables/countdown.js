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
