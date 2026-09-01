import {
    format,
    startOfWeek,
    endOfWeek,
    startOfMonth,
    endOfMonth,
    eachDayOfInterval,
    addWeeks,
    addMonths,
    isSameDay,
} from 'date-fns'
import useLocalDateTime from '@/Composables/useLocalDateTime'

// SCRUM-213/TT-2.6b: a thin date-fns wrapper for the counsellor calendar's week/month grid math --
// no other component in this codebase does week/month bucketing yet, so this establishes the
// pattern rather than reusing one.
export default function useCalendar() {
    const { toLocalDate } = useLocalDateTime()

    function weekRange(anchorDate) {
        return {
            start: startOfWeek(anchorDate),
            end: endOfWeek(anchorDate),
        }
    }

    // Padded to full weeks (not just the calendar month's own start/end) so the grid's weekday
    // columns line up correctly and the leading/trailing days from the adjacent month -- which
    // CalendarMonthView.vue renders dimmed, not omitted -- actually have their real session data
    // fetched too, rather than always appearing empty.
    function monthRange(anchorDate) {
        return {
            start: startOfWeek(startOfMonth(anchorDate)),
            end: endOfWeek(endOfMonth(anchorDate)),
        }
    }

    function daysInRange(start, end) {
        return eachDayOfInterval({ start, end })
    }

    // Backend Session start_time/end_time are stored and queried in UTC -- date-fns' format()
    // reads the Date object's LOCAL wall-clock getters, so it must not be used here (review
    // finding, SCRUM-213): for any non-UTC-timezone counsellor, the requested range would be
    // silently offset by their local UTC offset, dropping or mis-bucketing sessions near a
    // day/week boundary. .toISOString() matches the existing precedent already used for the same
    // local-Date-to-backend-datetime conversion in ProposeSessionScheduleModal.vue/
    // SessionScheduleCounterOfferModal.vue.
    function toApiDate(date) {
        return date.toISOString()
    }

    function eventsForDay(events, day, dateGetter) {
        return events.filter((event) => {
            const eventDate = toLocalDate(dateGetter(event))
            return eventDate && isSameDay(eventDate, day)
        })
    }

    return {
        toLocalDate,
        weekRange,
        monthRange,
        daysInRange,
        toApiDate,
        eventsForDay,
        addWeeks,
        addMonths,
        isSameDay,
        format,
    }
}
