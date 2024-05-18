import { parseISO, formatDistance } from "date-fns";

export default function useLocalDateTimed() {

    const toDiffForHumans = (dateTime) => {
        return formatDistance(parseISO(dateTime), new Date(), {addSuffix: true})
    }

    return { toDiffForHumans }
}