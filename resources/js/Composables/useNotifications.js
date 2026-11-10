// SCRUM-39 (TT-5.4c): the notification bell's state -- one copy for the app (module-level), like
// useAdminBadges. The count comes from its own JSON route and is refreshed on mount (throttled, so page
// visits don't each refetch it) and straight away when the server pings `notifications.changed` on the
// person's users.{id} channel (AuthenticatedLayout). The list is fetched only when the panel opens.
// Nothing here ever holds more than the server's fixed item shape (title, message, url).
//
// The state belongs to one signed-in person: forUser() clears it the moment someone else is signed in
// (logging out and in are Inertia visits, so this module survives them -- reviews: never show the
// previous person's notifications on a shared device).
import { ref } from 'vue'
import axios from 'axios'

const unread = ref(0)
const available = ref(true) // false when the server refuses (a suspended account): the bell hides
const items = ref([])
const nextCursor = ref(null)
const loading = ref(false)
const loadingMore = ref(false)
const failed = ref(false)
const panelOpen = ref(false)

const THROTTLE_MS = 15000

let owner = null
let lastCount = 0
let countInFlight = null
// Every list response carries the generation it was asked in; a reset or a newer first page makes older
// ones (a slow "Show more", an earlier refresh) arrive stale, and they are dropped (reviews).
let generation = 0
// Marked read here but maybe not yet on the server when a list response was made: kept read.
const markedHere = new Set()

function reset() {
    generation++
    unread.value = 0
    available.value = true
    items.value = []
    nextCursor.value = null
    loading.value = false
    loadingMore.value = false
    failed.value = false
    panelOpen.value = false
    lastCount = 0
    countInFlight = null
    markedHere.clear()
}

// The signed-in person's id (or null): everything is cleared when it changes.
export function forUser(userId) {
    if (owner === (userId ?? null)) return
    owner = userId ?? null
    reset()
}

export function refreshNotificationCount({ force = false } = {}) {
    if (!owner) return Promise.resolve(0)
    if (countInFlight) {
        return force ? countInFlight.then(() => refreshNotificationCount({ force: true })) : countInFlight
    }
    if (!force && Date.now() - lastCount < THROTTLE_MS) return Promise.resolve(unread.value)
    lastCount = Date.now()

    const asked = owner
    const request = axios.get(route('notifications.count'))
        .then((res) => {
            if (asked !== owner) return unread.value
            available.value = true
            unread.value = Number(res.data?.unread ?? 0)
            return unread.value
        })
        .catch((err) => {
            if (asked === owner && [401, 403].includes(err?.response?.status)) available.value = false
            // A count is a hint: any other failure keeps the last one.
            return unread.value
        })
        .finally(() => { if (countInFlight === request) countInFlight = null })
    countInFlight = request

    return request
}

function withLocalReads(list) {
    return list.map((item) => (markedHere.has(item.id) && !item.read ? { ...item, read: true } : item))
}

function fetchPage(cursor = null) {
    return axios.get(route('notifications.index', cursor ? { cursor } : {})).then((res) => ({
        notifications: withLocalReads(res.data?.notifications ?? []),
        nextCursor: res.data?.nextCursor ?? null,
    }))
}

// Opening the panel: the first page, from empty (never an earlier list while it loads).
async function loadFirst() {
    const asked = ++generation
    items.value = []
    nextCursor.value = null
    loading.value = true
    failed.value = false
    await fetchPage()
        .then((page) => {
            if (asked !== generation) return
            items.value = page.notifications
            nextCursor.value = page.nextCursor
        })
        .catch(() => { if (asked === generation) failed.value = true })
        .finally(() => { if (asked === generation) loading.value = false })
}

// A ping while the panel is open: the newest page merged in, without losing pages already shown or the
// scroll position (reviews, QA). The cursor for "Show more" stays the one for the oldest page shown.
async function refreshOpenList() {
    if (loading.value) return
    const asked = generation
    await fetchPage()
        .then((page) => {
            if (asked !== generation) return
            const fresh = new Set(page.notifications.map((item) => item.id))
            const older = items.value.filter((item) => !fresh.has(item.id))
            const onlyFirstPage = items.value.length <= page.notifications.length
            items.value = [...page.notifications, ...older]
            if (onlyFirstPage) nextCursor.value = page.nextCursor
        })
        .catch(() => {})
}

// Resolves to the items it added (the panel moves focus to the first of them).
async function loadMore() {
    if (!nextCursor.value || loadingMore.value) return []
    const asked = generation
    loadingMore.value = true
    return fetchPage(nextCursor.value)
        .then((page) => {
            if (asked !== generation) return []
            const seen = new Set(items.value.map((item) => item.id))
            const added = page.notifications.filter((item) => !seen.has(item.id))
            items.value = [...items.value, ...added]
            nextCursor.value = page.nextCursor
            return added
        })
        .catch(() => [])
        .finally(() => { if (asked === generation) loadingMore.value = false })
}

function markLocally(ids) {
    ids.forEach((id) => markedHere.add(id))
    items.value = items.value.map((item) => (ids.includes(item.id) ? { ...item, read: true } : item))
}

// One, when the person marks it or opens it. Never a safety notice: only its own dialog dismisses it.
async function markRead(item) {
    if (!item || item.read || item.safetyNotice) return
    markLocally([item.id])
    unread.value = Math.max(0, unread.value - 1)
    await axios.post(route('notifications.read', { notification: item.id })).catch(() => {})
    refreshNotificationCount({ force: true })
}

async function markAllRead() {
    const ids = items.value.filter((item) => !item.read && !item.safetyNotice).map((item) => item.id)
    markLocally(ids)
    // What's left: the unread safety notices in view (the server's count follows).
    unread.value = items.value.filter((item) => !item.read && item.safetyNotice).length
    await axios.post(route('notifications.read_all')).catch(() => {})
    refreshNotificationCount({ force: true })
}

// A safety notice dismissed in its dialog: read here too (its row shows only its title from now on).
export function safetyNoticeDismissed(id) {
    if (!id) return
    markedHere.add(id)
    items.value = items.value.map((item) => (item.id === id ? { ...item, read: true, message: '' } : item))
    refreshNotificationCount({ force: true })
}

// The server's ping: the count, and the open list if there is one.
export function notificationsChanged() {
    refreshNotificationCount({ force: true })
    if (panelOpen.value) refreshOpenList()
}

export default function useNotifications() {
    return { unread, available, items, nextCursor, loading, loadingMore, failed, panelOpen, refreshNotificationCount, loadFirst, loadMore, markRead, markAllRead }
}
