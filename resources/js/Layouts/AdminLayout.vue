<script setup>
// SCRUM-387: the admin area's shell -- a grouped sidebar (static from lg up, a slide-out drawer
// below it) around every admin page. It sits inside AuthenticatedLayout, so the alerts, the live
// suspension redirect and the guardian listeners keep working. Pages use it as a persistent layout
// (`defineOptions({ layout: AdminLayout })`), so both stay mounted -- and the users.{id} channel
// stays joined -- while moving between admin pages.
//
// The sidebar comes from the server (App\Support\AdminNavigation, already filtered by role). The
// badges come from their own small JSON route (useAdminBadges), fetched once a page is up and after
// each navigation, so no page waits on the counts or re-runs its own queries for them.
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AdminNavList from '@/Components/AdminNavList.vue'
import useAdminBadges from '@/Composables/useAdminBadges'
import { acquireChannel } from '@/Composables/sharedChannel'
import { router, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

const page = usePage()

const groups = computed(() => page.props.adminNavigation ?? [])
const { badges, refreshAdminBadges } = useAdminBadges()

// Every real page change, and every other successful visit -- a form post that redirects back to
// the same page (resolving a report, lifting a suspension...) replaces the page without a navigate
// event. Throttled in useAdminBadges, so the first load's mount, navigate and success fetch once.
const stopListening = router.on('navigate', () => {
    closeDrawer({ restoreFocus: false })
    refreshAdminBadges()
})
const stopListeningForSuccess = router.on('success', () => refreshAdminBadges())

// The drawer is for below lg only: grown past it, the static sidebar shows, so the drawer closes
// and lets the page scroll again.
let wideViewport = null

function onViewportChange(event) {
    if (event.matches) closeDrawer({ restoreFocus: false })
}

// SCRUM-33 (TT-4.4b): the Urgent help badge is live -- someone waiting can't wait for a page change.
let helpQueue = null

onMounted(() => {
    refreshAdminBadges()
    helpQueue = acquireChannel('admin.help-requests')
    helpQueue?.listen('.help.queue', () => refreshAdminBadges({ force: true }))
    wideViewport = window.matchMedia('(min-width: 1024px)')
    wideViewport.addEventListener('change', onViewportChange)
})

// Mobile drawer.
const drawerOpen = ref(false)
const toggleButton = ref(null)
const drawer = ref(null)

function openDrawer() {
    drawerOpen.value = true
    document.body.style.overflow = 'hidden'
    nextTick(() => focusables()[0]?.focus())
}

function closeDrawer({ restoreFocus = true } = {}) {
    if (!drawerOpen.value) return

    drawerOpen.value = false
    document.body.style.overflow = ''
    if (restoreFocus) nextTick(() => toggleButton.value?.focus())
}

function focusables() {
    return drawer.value
        ? Array.from(drawer.value.querySelectorAll('a[href], button:not([disabled])'))
        : []
}

function onDrawerKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault()
        closeDrawer()
        return
    }

    if (event.key !== 'Tab') return

    const items = focusables()
    if (!items.length) return

    const first = items[0]
    const last = items[items.length - 1]

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
    }
}

onBeforeUnmount(() => {
    helpQueue?.release()
    stopListening()
    stopListeningForSuccess()
    wideViewport?.removeEventListener('change', onViewportChange)
    document.body.style.overflow = ''
})
</script>

<template>
    <AuthenticatedLayout>
        <a
            href="#admin-main"
            class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:px-4 focus:py-3 focus:bg-white focus:text-gray-900 focus:rounded-md focus:shadow-lg focus:border focus:border-gray-300"
        >Skip to main content</a>

        <div class="lg:flex lg:items-start">
            <!-- Desktop: a static sidebar. -->
            <!-- While the drawer is open, everything behind it is inert (no focus, no clicks). -->
            <aside :inert="drawerOpen || null" class="hidden lg:block w-64 shrink-0 bg-white border-r border-gray-200 lg:min-h-[calc(100vh-4rem)]">
                <AdminNavList :groups="groups" :badges="badges" />
            </aside>

            <!-- Below lg: a toggle for the drawer. -->
            <div :inert="drawerOpen || null" class="lg:hidden bg-white border-b border-gray-200 px-4 py-2">
                <button
                    ref="toggleButton"
                    type="button"
                    class="inline-flex items-center gap-2 min-h-[44px] px-3 rounded-md border border-gray-300 bg-white text-sm text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500"
                    aria-controls="admin-drawer"
                    :aria-expanded="drawerOpen ? 'true' : 'false'"
                    @click="openDrawer"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    Administration menu
                </button>
            </div>

            <div
                v-if="drawerOpen"
                class="lg:hidden fixed inset-0 z-40 bg-gray-900/50"
                aria-hidden="true"
                @click="() => closeDrawer()"
            ></div>
            <div
                v-show="drawerOpen"
                id="admin-drawer"
                ref="drawer"
                role="dialog"
                aria-modal="true"
                aria-label="Administration menu"
                class="lg:hidden fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] bg-white shadow-lg border-r border-gray-200 overflow-y-auto"
                @keydown="onDrawerKeydown"
            >
                <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200">
                    <span class="text-sm font-semibold text-gray-900">Administration</span>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] rounded-md text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-500"
                        @click="() => closeDrawer()"
                    >
                        <span class="sr-only">Close menu</span>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <AdminNavList :groups="groups" :badges="badges" @navigate="() => closeDrawer({ restoreFocus: false })" />
            </div>

            <div id="admin-main" :inert="drawerOpen || null" tabindex="-1" class="flex-1 min-w-0 focus:outline-none">
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
