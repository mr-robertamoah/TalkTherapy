import { parseISO, formatDistance, format } from "date-fns";

export default function useUtilities() {

    const toCapitalize = (str) => {
        if (!str) return

        if (str.length == 1) return str.toLowerCase()

        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase()
    }

    const getReadableStatus = (status) => {
        if (!status) return ''

        return status.toLowerCase().replaceAll('_', ' ')
    }

    const formatDateToStandard = (date) => {
        return format(date, 'MMM dd, yyyy - HH:mm')
    }

    return {
        toCapitalize, getReadableStatus, formatDateToStandard
    }
}