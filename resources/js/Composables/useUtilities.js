import { parseISO, formatDistance } from "date-fns";

export default function useUtilities() {

    const toCapitalize = (str) => {
        if (!str) return

        if (str.length == 1) return str.toLowerCase()

        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase()
    }

    return { toCapitalize }
}