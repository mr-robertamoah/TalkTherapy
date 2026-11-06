import { ref } from 'vue'
import axios from 'axios'

// SCRUM-387 (review): the admin sidebar's badges, from their own small JSON route (admin.badges ->
// GetAdminToDoCountsAction::badges()), never a reload of the page (which re-ran the whole page's
// controller). One copy for the app: AdminLayout shows it and refreshes it after each page; a page
// that has just resolved something calls refreshAdminBadges({ force: true }) so its badge drops
// straight away (the server cleared its cached counts). The client is Inertia 1.x, which has no
// deferred props -- see .claude/memory.md.

const badges = ref({})

const THROTTLE_MS = 1000

let lastLoad = 0
let inFlight = null

export function refreshAdminBadges({ force = false } = {}) {
    if (inFlight) {
        // A forced refresh after an action must not be answered by a fetch that began before it.
        return force ? inFlight.then(() => refreshAdminBadges({ force: true })) : inFlight
    }

    if (!force && Date.now() - lastLoad < THROTTLE_MS) return Promise.resolve(badges.value)
    lastLoad = Date.now()

    inFlight = axios.get(route('admin.badges'))
        .then((res) => {
            badges.value = res.data?.badges ?? {}
            return badges.value
        })
        // A badge is a hint: a failed fetch keeps the last ones.
        .catch(() => badges.value)
        .finally(() => {
            inFlight = null
        })

    return inFlight
}

export default function useAdminBadges() {
    return { badges, refreshAdminBadges }
}
