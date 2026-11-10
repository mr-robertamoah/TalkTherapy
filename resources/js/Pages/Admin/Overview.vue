<script setup>
// SCRUM-387 (+ SCRUM-49): the admin area's overview -- what is waiting on the administrators, each
// linking to where it is resolved, and the platform figures (GetPlatformStatsAction): totals now,
// and this week / this month / all time with the previous period alongside. Totals only.
import { Head, Link } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    administrator: { type: Object, default: null },
    toDo: { type: Array, required: true },
    stats: { type: Object, required: true },
})

const periods = [
    { key: 'week', label: 'This week', previous: 'last week' },
    { key: 'month', label: 'This month', previous: 'last month' },
    { key: 'all', label: 'All time', previous: null },
]
const periodKey = ref('week')
const period = computed(() => periods.find((p) => p.key === periodKey.value))
const current = computed(() => props.stats.periods?.[periodKey.value]?.current ?? {})
const previous = computed(() => props.stats.periods?.[periodKey.value]?.previous ?? null)

const totals = computed(() => props.stats.totals ?? {})

const activity = computed(() => [
    { key: 'newUsers', label: 'New users' },
    { key: 'sessionsHeld', label: 'Sessions held' },
    { key: 'sessionsFailedOrAbandoned', label: 'Sessions failed or abandoned' },
    { key: 'videoMinutesApprox', label: 'Video call minutes (approximate)' },
    { key: 'reportsOpened', label: 'Reports opened' },
])

const moneyRows = [
    { key: 'revenue', label: 'Revenue (successful payments)' },
    { key: 'platformFees', label: 'Platform fees (not reversed)' },
    { key: 'payoutsPaid', label: 'Payouts paid' },
    { key: 'refundsPaid', label: 'Refunds paid' },
    { key: 'invoicesSettled', label: 'Organisation invoices settled' },
]

const currencies = computed(() => {
    const codes = new Set()
    moneyRows.forEach((row) => {
        Object.keys(current.value.money?.[row.key] ?? {}).forEach((code) => codes.add(code))
        Object.keys(previous.value?.money?.[row.key] ?? {}).forEach((code) => codes.add(code))
    })

    return [...codes].sort()
})

function href(item) {
    return route(item.route, item.params ?? {})
}

function waiting(count) {
    return count ? `${count} waiting` : 'nothing waiting'
}

function change(now, before, formatter = (value) => value) {
    if (before === null || before === undefined) return null
    const diff = (now ?? 0) - before
    if (diff === 0) return `same as ${period.value.previous}`

    return `${diff > 0 ? '+' : '-'}${formatter(Math.abs(diff))} vs ${period.value.previous}`
}

function money(minorUnits) {
    return ((minorUnits ?? 0) / 100).toFixed(2)
}

function share(value) {
    return value === null || value === undefined ? '--' : `${Math.round(value * 100)}%`
}

const asOf = computed(() => props.stats.asOf ? new Date(props.stats.asOf).toLocaleString() : null)
</script>

<template>
    <Head title="Administration" />

    <div class="my-8 w-full max-w-6xl mx-auto space-y-8 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Overview</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">
                Welcome {{ $page.props.auth.user?.fullName }}. You are
                {{ administrator?.type === 'SUPER' ? 'a super administrator' : 'an administrator' }}.
            </p>
        </div>

        <section aria-labelledby="todo-heading" class="space-y-3">
            <h2 id="todo-heading" class="text-lg font-semibold text-gray-900">Waiting on an administrator</h2>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="item in toDo" :key="item.key">
                    <component
                        :is="item.route ? Link : 'div'"
                        :href="item.route ? href(item) : undefined"
                        class="flex h-full flex-col justify-between gap-2 min-h-[44px] rounded-lg border bg-white p-4 shadow-sm"
                        :class="[
                            item.count ? 'border-amber-200' : 'border-gray-200',
                            item.route ? 'hover:border-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500' : '',
                        ]"
                    >
                        <span class="text-sm text-gray-700">{{ item.label }}</span>
                        <span
                            class="text-base font-semibold"
                            :class="item.count ? 'text-amber-800' : 'text-gray-500'"
                        >{{ waiting(item.count) }}</span>
                    </component>
                </li>
            </ul>
        </section>

        <section aria-labelledby="stats-heading" class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="stats-heading" class="text-lg font-semibold text-gray-900">Platform</h2>
                    <p v-if="asOf" class="text-xs text-gray-500">As of {{ asOf }} (refreshed every 15 minutes)</p>
                </div>
                <div class="flex flex-wrap gap-2" role="group" aria-label="Period">
                    <button
                        v-for="p in periods"
                        :key="p.key"
                        type="button"
                        class="min-h-[44px] px-3 text-sm rounded-md border transition duration-75"
                        :class="periodKey === p.key
                            ? 'bg-gray-800 text-white border-gray-800'
                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                        :aria-pressed="periodKey === p.key ? 'true' : 'false'"
                        @click="() => periodKey = p.key"
                    >{{ p.label }}</button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6">
                    <h3 class="text-sm font-semibold text-gray-900">Right now</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div><dt class="text-gray-500">Users</dt><dd class="font-semibold text-gray-900">{{ totals.users }}</dd></div>
                        <div>
                            <dt class="text-gray-500">Counsellors</dt>
                            <dd class="font-semibold text-gray-900">{{ totals.counsellors }}</dd>
                            <dd class="text-xs text-gray-600">{{ totals.counsellorsVerified }} verified, {{ totals.counsellorsAwaiting }} awaiting</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Organisations</dt>
                            <dd class="font-semibold text-gray-900">{{ totals.organizations }}</dd>
                            <dd class="text-xs text-gray-600">{{ totals.organizationsVerified }} verified</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Therapies in session</dt>
                            <dd class="font-semibold text-gray-900">{{ totals.activeTherapies }}</dd>
                            <dd class="text-xs text-gray-600">{{ totals.pendingTherapies }} pending</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Group therapies in session</dt>
                            <dd class="font-semibold text-gray-900">{{ totals.activeGroupTherapies }}</dd>
                            <dd class="text-xs text-gray-600">{{ totals.pendingGroupTherapies }} pending</dd>
                        </div>
                        <div><dt class="text-gray-500">Upcoming sessions</dt><dd class="font-semibold text-gray-900">{{ totals.upcomingSessions }}</dd></div>
                        <div><dt class="text-gray-500">Suspensions in force</dt><dd class="font-semibold text-gray-900">{{ totals.activeSuspensions }}</dd></div>
                    </dl>
                </div>

                <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6">
                    <h3 class="text-sm font-semibold text-gray-900">{{ period.label }}</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div v-for="row in activity" :key="row.key">
                            <dt class="text-gray-500">{{ row.label }}</dt>
                            <dd class="font-semibold text-gray-900">{{ current[row.key] ?? 0 }}</dd>
                            <dd v-if="previous" class="text-xs text-gray-600">{{ change(current[row.key], previous[row.key]) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Share of sessions failed or abandoned</dt>
                            <dd class="font-semibold text-gray-900">{{ share(current.failedOrAbandonedShare) }}</dd>
                            <dd v-if="previous" class="text-xs text-gray-600">{{ share(previous.failedOrAbandonedShare) }} {{ period.previous }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6">
                <h3 class="text-sm font-semibold text-gray-900">Money, {{ period.label.toLowerCase() }}</h3>
                <p class="text-xs text-gray-500">Per currency, never added across currencies. Successful, non-reversed amounts only.</p>

                <div v-if="currencies.length === 0" class="mt-3 text-sm text-gray-600">No money moved in this period.</div>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 font-medium"></th>
                                <th v-for="code in currencies" :key="code" scope="col" class="py-2 pr-4 font-medium">{{ code }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in moneyRows" :key="row.key" class="border-b border-gray-100 align-top">
                                <th scope="row" class="py-2 pr-4 font-normal text-gray-700">{{ row.label }}</th>
                                <td v-for="code in currencies" :key="code" class="py-2 pr-4">
                                    <div class="font-semibold text-gray-900">{{ money(current.money?.[row.key]?.[code]) }}</div>
                                    <div v-if="previous" class="text-xs text-gray-600">
                                        {{ change(current.money?.[row.key]?.[code] ?? 0, previous.money?.[row.key]?.[code] ?? 0, money) }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
