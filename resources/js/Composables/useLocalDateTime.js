import { parseISO, formatDistance, formatDistanceToNow } from "date-fns";

export default function useLocalDateTimed() {

    const toDiffForHumans = (dateTime) => {
        // console.log(parseISO('2024-05-18T23:45:30.000000Z'))
        // console.log(formatDistanceToNow(new Date('2024-05-18T23:45:30.000000Z'), { addSuffix: true }))
        return formatDistanceToNow(new Date(dateTime), { addSuffix: true })
    }

    return { toDiffForHumans }
}