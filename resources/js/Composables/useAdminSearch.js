// SCRUM-390: an admin list page that the server pages (Inertia) -- a new search reloads it from the
// first page, keeping the page's other filters, with ?search= in the URL (so it can be bookmarked,
// and Back works).
import { router } from '@inertiajs/vue3'

// preserveState: false when the page should start again (e.g. a different filter), true for a search
// the page itself reacts to.
export function searchPage(routeName, search, keep = {}, preserveState = true) {
    const query = Object.fromEntries(Object.entries({ ...keep, search: search || undefined }).filter(([, value]) => value !== undefined && value !== null && value !== ''))

    router.get(route(routeName), query, { preserveState, preserveScroll: true, replace: preserveState })
}
