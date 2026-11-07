<script setup>
// SCRUM-391: a counsellor's own dashboard (first version) -- what needs them, the next week's
// sessions, their current therapies and groups, their verification, their earnings, and public
// therapies they could help with. Everything comes from GetCounsellorDashboardAction; each block
// shows a few items and links to where the full list lives.
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import RequestBadge from '@/Components/RequestBadge.vue'
import CounsellorVerificationStatusCard from '@/Components/CounsellorVerificationStatusCard.vue'
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue'
import MiniGroupTherapyComponent from '@/Components/MiniGroupTherapyComponent.vue'
import Alert from '@/Components/Alert.vue'
import useAlert from '@/Composables/useAlert'

const props = defineProps({
    dashboard: { type: Object, required: true },
})

const { alertData, clearAlertData, setAlertData } = useAlert()
const counsellorId = usePage().props.auth.user?.counsellor?.id

function formatMoney(minorUnits, currency) {
    if (minorUnits == null || !currency) return '--'
    return `${currency} ${(minorUnits / 100).toFixed(2)}`
}

function formatWhen(value) {
    return value ? new Date(value).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

function sessionHref(session) {
    if (!session.for?.id) return null
    return session.forType === 'group'
        ? route('group.therapies.get', { groupTherapyId: session.for.id })
        : route('therapies.get', { therapyId: session.for.id })
}

// An answered request reloads the dashboard (counts change).
function answered() {
    router.reload({ only: ['dashboard'], preserveScroll: true })
}

const card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5'
const heading = 'text-base font-semibold text-gray-900'
const more = 'text-sm font-medium text-blue-700 underline'
</script>

<template>
    <Head title="My dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">My dashboard</h2>
        </template>

        <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-8">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Needs your action -->
                <section :class="card" aria-labelledby="needs-action">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 id="needs-action" :class="heading">Needs your action</h3>
                        <span class="text-sm text-gray-600">{{ dashboard.needsAction.total }} waiting</span>
                    </div>
                    <p v-if="!dashboard.needsAction.total" class="mt-3 text-sm text-gray-600">Nothing is waiting on you.</p>
                    <div v-else class="mt-3 space-y-3">
                        <RequestBadge
                            v-for="request in dashboard.needsAction.items"
                            :key="request.id"
                            :request="request"
                            class="!max-w-none"
                            @on-data="answered"
                            @refused="answered"
                            @alert="(data) => setAlertData(data)"
                        />
                        <p v-if="dashboard.needsAction.total > dashboard.needsAction.items.length" class="text-sm text-gray-600">
                            And {{ dashboard.needsAction.total - dashboard.needsAction.items.length }} more in your requests.
                        </p>
                    </div>
                </section>

                <!-- Upcoming sessions -->
                <section :class="card" aria-labelledby="upcoming-sessions">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 id="upcoming-sessions" :class="heading">Upcoming sessions (next 7 days)</h3>
                        <Link :href="route('counsellor.calendar')" :class="more">Calendar</Link>
                    </div>
                    <p v-if="!dashboard.upcomingSessions.total" class="mt-3 text-sm text-gray-600">No sessions in the next 7 days.</p>
                    <ul v-else class="mt-3 divide-y divide-gray-100">
                        <li v-for="session in dashboard.upcomingSessions.items" :key="session.id" class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <component :is="sessionHref(session) ? Link : 'span'" :href="sessionHref(session)" class="block truncate text-sm font-medium text-gray-900">{{ session.name }}</component>
                                <div class="truncate text-xs text-gray-600">{{ session.for?.name }}</div>
                            </div>
                            <span class="shrink-0 text-xs text-gray-700">{{ formatWhen(session.startTime) }}</span>
                        </li>
                    </ul>
                </section>

                <!-- Verification -->
                <section aria-label="Verification">
                    <CounsellorVerificationStatusCard
                        :verification="dashboard.verification"
                        @verify="() => router.visit(route('counsellor.show', { counsellorId }) + '?verify=1')"
                    />
                </section>

                <!-- Earnings -->
                <section :class="card" aria-labelledby="earnings">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 id="earnings" :class="heading">Earnings</h3>
                        <Link :href="route('counsellor.show', { counsellorId })" :class="more">Payouts</Link>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-gray-600">Available to withdraw</dt>
                            <dd class="font-semibold text-gray-900">{{ dashboard.earnings.availableAmount ? formatMoney(dashboard.earnings.availableAmount, dashboard.earnings.currency) : 'Nothing yet' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">Last payout</dt>
                            <dd class="text-gray-900">
                                <template v-if="dashboard.earnings.lastPayout">
                                    {{ formatMoney(dashboard.earnings.lastPayout.amount, dashboard.earnings.lastPayout.currency) }}
                                    <span class="text-xs text-gray-600">({{ dashboard.earnings.lastPayout.status.toLowerCase() }})</span>
                                </template>
                                <template v-else>None yet</template>
                            </dd>
                        </div>
                    </dl>
                    <p v-if="dashboard.earnings.onHold" class="mt-3 text-sm text-amber-800">Payouts are on hold while your counsellor role is suspended.</p>
                    <p v-else-if="!dashboard.earnings.hasPayoutDestination" class="mt-3 text-sm text-gray-600">Add a payout destination on your profile to withdraw.</p>
                </section>
            </div>

            <!-- Current therapies and groups -->
            <section :class="card" aria-labelledby="current-work">
                <h3 id="current-work" :class="heading">Your current therapies and groups</h3>
                <div class="mt-3 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div v-for="kind in [{ key: 'therapies', label: 'Therapies', route: 'therapies.get', param: 'therapyId' }, { key: 'groups', label: 'Groups', route: 'group.therapies.get', param: 'groupTherapyId' }]" :key="kind.key">
                        <div class="flex items-baseline justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-800">{{ kind.label }}</h4>
                            <span class="text-xs text-gray-600">{{ dashboard.current[kind.key].total }} in all</span>
                        </div>
                        <p v-if="!dashboard.current[kind.key].total" class="mt-2 text-sm text-gray-600">None at the moment.</p>
                        <ul v-else class="mt-2 divide-y divide-gray-100">
                            <li v-for="item in dashboard.current[kind.key].items" :key="item.id" class="flex items-center justify-between gap-2 py-2">
                                <span class="min-w-0 truncate text-sm text-gray-900">{{ item.name }}</span>
                                <span v-if="item.viewerAccessPaused" class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs text-amber-800">Paused</span>
                                <Link :href="route(kind.route, { [kind.param]: item.id })" class="shrink-0 text-sm font-medium text-blue-700 underline">Open</Link>
                            </li>
                        </ul>
                        <Link v-if="dashboard.current[kind.key].total > dashboard.current[kind.key].items.length" :href="route('therapies')" class="mt-2 inline-block text-sm text-blue-700 underline">See all</Link>
                    </div>
                </div>
            </section>

            <!-- Therapies you could help -->
            <section v-if="dashboard.couldHelp" :class="card" aria-labelledby="could-help">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 id="could-help" :class="heading">Therapies you could help</h3>
                    <Link :href="route('public.therapies')" :class="more">See all</Link>
                </div>
                <p class="mt-1 text-sm text-gray-600">Public therapies and groups looking for a counsellor, closest to your profile first.</p>
                <p v-if="!dashboard.couldHelp.total" class="mt-3 text-sm text-gray-600">None looking for a counsellor right now.</p>
                <div v-else class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <template v-for="item in dashboard.couldHelp.items" :key="`${item.type}-${item.id}`">
                        <MiniGroupTherapyComponent v-if="item.type === 'group'" :group-therapy="item" />
                        <MiniTherapyComponent v-else :therapy="item" />
                    </template>
                </div>
            </section>
        </div>

        <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
    </AuthenticatedLayout>
</template>
