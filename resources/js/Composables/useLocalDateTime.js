import { parseISO, formatDistance } from "date-fns";

export default function useLocalDateTimed() {

    const toDiffForHumans = (dateTime) => {
        return formatDistance(parseISO(dateTime), new Date(), {addSuffix: true})
    }

    // Stored server-side via Carbon::toDateTimeString() in UTC (space-separated, no 'T'/offset) --
    // normalize to a proper ISO string before parsing so this always resolves to the correct
    // instant regardless of what the browser's Date constructor would otherwise guess. Extracted
    // (SCRUM-213) from two independent copies of this same transform in
    // SessionScheduleProposalSection.vue and useCalendar.js.
    const toLocalDate = (dateTime) => {
        if (!dateTime) return null
        const iso = dateTime.includes('T') ? dateTime : `${dateTime.replace(' ', 'T')}Z`
        return parseISO(iso)
    }

    return { toDiffForHumans, toLocalDate }
}