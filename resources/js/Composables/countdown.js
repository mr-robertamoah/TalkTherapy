import { differenceInMinutes, parseISO } from 'date-fns'

// SCRUM-369: minutes from `now` until a session's or discussion's start and end -- the one copy of
// this maths (used by useTherapyState's header timer and DiscussionModal). The getTimezoneOffset()
// adjustment is the app's existing handling of the server's times; change it here only.
export function minutesUntilWindow(startTime, endTime, now = new Date()) {
    const offset = now.getTimezoneOffset()

    return {
        beforeStart: differenceInMinutes(parseISO(startTime), now) + offset,
        beforeEnd: differenceInMinutes(parseISO(endTime), now) + offset,
    }
}
