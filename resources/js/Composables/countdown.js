import { differenceInSeconds, parseISO } from 'date-fns'

// SCRUM-369: minutes from `now` until a session's or discussion's start and end -- the one copy of
// this maths (used by useTherapyState's header timer and DiscussionModal). The getTimezoneOffset()
// adjustment is the app's existing handling of the server's times; change it here only.
//
// SCRUM-372: rounded so a whole minute is never claimed early -- "to start" rounds up (it is not
// 0 until the start time has actually passed; truncating enabled "start" up to 59s early), and
// "to end" rounds down (negative as soon as the end time has passed).
export function minutesUntilWindow(startTime, endTime, now = new Date()) {
    const offset = now.getTimezoneOffset()

    return {
        beforeStart: Math.ceil(differenceInSeconds(parseISO(startTime), now) / 60) + offset,
        beforeEnd: Math.floor(differenceInSeconds(parseISO(endTime), now) / 60) + offset,
    }
}
