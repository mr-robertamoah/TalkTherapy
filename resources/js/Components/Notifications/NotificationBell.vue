<script setup>
// SCRUM-39 (TT-5.4c): the notification bell in the main layout -- an unread count (9+ past nine) and a
// panel of the person's notifications (theirs and their counsellor profile's, labelled), 15 at a time with
// "Show more". Opening one marks it read and follows its link; an unread safety notice opens its own dialog
// instead and is only dismissed there (a read one shows its title only -- decision log). No toasts or
// previews: nothing shows until the person opens it. Text is rendered as text only ({{ }}), never as HTML.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import useNotifications, { forUser } from '@/Composables/useNotifications'
import useSafetyNotice from '@/Composables/useSafetyNotice'
import useLocalDateTime from '@/Composables/useLocalDateTime'

const page = usePage()
const { unread, available, items, nextCursor, loading, loadingMore, failed, panelOpen, refreshNotificationCount, loadFirst, loadMore, markRead, markAllRead } = useNotifications()
const { open: openSafetyNotice } = useSafetyNotice()
const { toDiffForHumans } = useLocalDateTime()

const root = ref(null)
const button = ref(null)
const heading = ref(null)

const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)))
const label = computed(() => (unread.value > 0 ? `Notifications, ${unread.value} unread` : 'Notifications'))
const canMarkAll = computed(() => items.value.some((item) => !item.read && !item.safetyNotice))

function when(value) {
    try {
        return value ? toDiffForHumans(value) : ''
    } catch {
        return ''
    }
}

// Each item's main button, for moving focus to it.
function itemButtonId(item) {
    return `notification-item-${item.id}`
}

function focusItem(item) {
    nextTick(() => document.getElementById(itemButtonId(item))?.focus())
}

function focusHeading() {
    nextTick(() => heading.value?.focus())
}

async function openPanel() {
    panelOpen.value = true
    loadFirst()
    focusHeading()
}

function close({ returnFocus = true } = {}) {
    if (!panelOpen.value) return
    panelOpen.value = false
    if (returnFocus) button.value?.focus()
}

function toggle() {
    panelOpen.value ? close() : openPanel()
}

// Only a link to this site is followed (security review: defence in depth -- they are built server-side).
function sameOrigin(url) {
    try {
        return new URL(url, window.location.origin).origin === window.location.origin
    } catch {
        return false
    }
}

function openItem(item) {
    if (item.safetyNotice) {
        if (item.read) return // dismissed: kept as a record, nothing to open
        // Focus goes back to the bell first, so the dialog returns it there when it closes.
        close()
        openSafetyNotice({ id: item.id, message: item.message })
        return
    }

    markRead(item)
    if (item.url && sameOrigin(item.url)) {
        close({ returnFocus: false })
        router.visit(item.url)
    }
}

// The check button disappears once used: focus stays on the item it belongs to (reviews, QA).
function markOne(item) {
    markRead(item)
    focusItem(item)
}

// "Mark all" disappears once used: focus moves to the heading.
function markAll() {
    markAllRead()
    focusHeading()
}

// "Show more" may disappear (the last page): focus moves to the first item it loaded.
async function showMore() {
    if (loadingMore.value) return
    const added = await loadMore()
    if (added.length) focusItem(added[0])
    else focusHeading()
}

// Closes on a press or focus anywhere outside it, on Escape (wherever focus is -- QA), and on phones when
// the page scrolls (the panel is fixed there and would drift from the bell).
function outside(event) {
    return panelOpen.value && root.value && !root.value.contains(event.target)
}
function onPointerDown(event) {
    if (outside(event)) close({ returnFocus: false })
}
function onFocusIn(event) {
    if (outside(event)) close({ returnFocus: false })
}
function onKeydown(event) {
    if (event.key === 'Escape' && panelOpen.value) {
        event.stopPropagation()
        close()
    }
}
function onScroll() {
    if (panelOpen.value && window.innerWidth < 640) close({ returnFocus: false })
}

let stopNavigate = null
onMounted(() => {
    forUser(page.props.auth?.user?.id)
    refreshNotificationCount()
    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('focusin', onFocusIn)
    document.addEventListener('keydown', onKeydown)
    window.addEventListener('scroll', onScroll, { passive: true })
    // A page visit closes it.
    stopNavigate = router.on('navigate', () => close({ returnFocus: false }))
})
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown)
    document.removeEventListener('focusin', onFocusIn)
    document.removeEventListener('keydown', onKeydown)
    window.removeEventListener('scroll', onScroll)
    stopNavigate?.()
    panelOpen.value = false
})

// Someone else signed in: nothing of the previous person's is kept (reviews).
watch(() => page.props.auth?.user?.id, (id) => {
    forUser(id)
    refreshNotificationCount()
})
watch(available, (isAvailable) => { if (!isAvailable) panelOpen.value = false })
</script>

<template>
    <div v-if="available" ref="root" class="relative flex items-center">
        <button
            ref="button"
            type="button"
            class="relative inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            :aria-label="label"
            :aria-expanded="panelOpen ? 'true' : 'false'"
            aria-haspopup="dialog"
            :aria-controls="panelOpen ? 'notification-panel' : undefined"
            data-testid="notification-bell"
            @click="toggle"
        >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
            </svg>
            <span
                v-if="unread > 0"
                class="absolute -top-0.5 -right-0.5 min-w-[1.25rem] h-5 px-1 rounded-full bg-red-600 text-white text-xs font-semibold leading-5 text-center"
                aria-hidden="true"
                data-testid="notification-badge"
            >{{ badge }}</span>
        </button>

        <div
            v-if="panelOpen"
            id="notification-panel"
            role="dialog"
            aria-labelledby="notification-panel-title"
            class="fixed inset-x-2 top-16 z-50 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 bg-white rounded-lg shadow-lg border border-gray-200 text-gray-900"
        >
            <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100">
                <h2 id="notification-panel-title" ref="heading" tabindex="-1" class="text-base font-semibold focus:outline-none">Notifications</h2>
                <button
                    v-if="canMarkAll"
                    type="button"
                    class="text-sm text-indigo-700 hover:text-indigo-900 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded"
                    @click="markAll"
                >Mark all as read</button>
            </div>

            <div class="max-h-[70vh] sm:max-h-[28rem] overflow-y-auto">
                <p v-if="loading" class="px-4 py-6 text-sm text-gray-500">Loading…</p>
                <div v-else-if="failed" class="px-4 py-6 text-sm text-gray-600 space-y-2">
                    <p>Your notifications couldn't be loaded.</p>
                    <button type="button" class="underline text-gray-800 hover:text-gray-900" @click="loadFirst">Try again</button>
                </div>
                <p v-else-if="items.length === 0" class="px-4 py-6 text-sm text-gray-500">No notifications yet.</p>

                <ul v-else class="divide-y divide-gray-100" aria-label="Your notifications">
                    <li v-for="item in items" :key="item.id" class="flex items-start gap-2 px-4 py-3" :class="item.read ? 'bg-white' : 'bg-indigo-50'">
                        <button
                            :id="itemButtonId(item)"
                            type="button"
                            class="flex-1 min-w-0 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded"
                            :class="{ 'cursor-default': item.safetyNotice && item.read }"
                            :aria-disabled="item.safetyNotice && item.read ? 'true' : undefined"
                            data-testid="notification-item"
                            @click="openItem(item)"
                        >
                            <span v-if="!item.read" class="sr-only">Unread: </span>
                            <span class="block text-sm break-words" :class="item.read ? 'text-gray-700' : 'font-semibold text-gray-900'">{{ item.title }}</span>
                            <span v-if="item.message" class="block mt-0.5 text-sm text-gray-600 break-words line-clamp-3">{{ item.message }}</span>
                            <span class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-600">
                                <span>{{ when(item.createdAt) }}</span>
                                <span v-if="item.audience === 'counsellor'" class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-700">As counsellor</span>
                                <span v-if="item.safetyNotice && !item.read">Opens the message</span>
                            </span>
                        </button>
                        <button
                            v-if="!item.read && !item.safetyNotice"
                            type="button"
                            class="shrink-0 p-1 rounded text-gray-500 hover:text-gray-800 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                            :aria-label="`Mark '${item.title}' as read`"
                            title="Mark as read"
                            @click="markOne(item)"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </button>
                    </li>
                </ul>

                <div v-if="nextCursor && !loading" class="px-4 py-3 border-t border-gray-100">
                    <button
                        type="button"
                        class="w-full text-sm underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded"
                        :class="loadingMore ? 'text-gray-500 cursor-wait' : 'text-gray-800 hover:text-gray-900'"
                        :aria-disabled="loadingMore ? 'true' : 'false'"
                        @click="showMore"
                    >{{ loadingMore ? 'Loading…' : 'Show more' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
