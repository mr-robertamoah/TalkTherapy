<script setup>
// SCRUM-387: "Requests to resolve" -- one queue of everything waiting on an administrator
// (AdminRequestController). Each row is the personal Requests list's own badge, acting through
// requests.respond; the row itself says whether the viewer may answer it (`isRespondent`) and who
// answered it (`respondedBy`). Filters and the page are in the query string.
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import RequestBadge from '@/Components/RequestBadge.vue'
import useAlert from '@/Composables/useAlert'
import useEnums from '@/Composables/useEnums'
import { refreshAdminBadges } from '@/Composables/useAdminBadges'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    requests: { type: Object, required: true },
    rows: { type: [Object, Array], default: () => ({}) },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    statuses: { type: Array, required: true },
})

const { alertData, clearAlertData, setAlertData } = useAlert()
const { RequestStatusLabels } = useEnums()

const statusFilters = [
    ...props.statuses.map((value) => ({ value, label: RequestStatusLabels[value] ?? value })),
    { value: 'all', label: 'All' },
]

// Each type's label and own page (App\Support\AdminRequestQueue), by type.
const typesByValue = computed(() => Object.fromEntries(props.types.map((type) => [type.value, type])))

const includesSafeguarding = computed(() => props.types.some((type) => type.value === 'SAFEGUARDING_EXCEPTION_REQUEST'))

function filterBy(changes) {
    const query = { ...props.filters, ...changes }
    Object.keys(query).forEach((key) => {
        if (!query[key]) delete query[key]
    })
    if (query.status === 'PENDING') delete query.status

    router.get(route('administrator.requests'), query, { preserveScroll: true })
}

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}

function rowFor(request) {
    return props.rows?.[request.id] ?? {}
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}

// Answered, or refused (already answered elsewhere -- SCRUM-388): reload the list and the sidebar's
// badges (the server cleared its cached counts).
function responded() {
    router.reload({ only: ['requests', 'rows'], preserveScroll: true })
    refreshAdminBadges({ force: true })
}
</script>

<template>
    <Head title="Requests to resolve" />

    <div class="my-8 w-full max-w-5xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Requests to resolve</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">
                <template v-if="includesSafeguarding">Everything waiting on an administrator: verifications, age and date-of-birth checks, refunds and safeguarding exceptions.</template>
                <template v-else>Everything waiting on an administrator: verifications, age and date-of-birth checks, and refunds.</template>
            </p>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 flex flex-col sm:flex-row sm:items-end gap-4">
            <div>
                <label for="request-type-filter" class="block text-sm font-medium text-gray-700">Type</label>
                <select
                    id="request-type-filter"
                    class="mt-1 block w-full sm:w-72 min-h-[44px] rounded-md border-gray-300 text-sm focus:border-gray-500 focus:ring-gray-500"
                    :value="filters.type ?? ''"
                    @change="(event) => filterBy({ type: event.target.value || null })"
                >
                    <option value="">All types</option>
                    <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-gray-700">Status</legend>
                <div class="mt-1 flex flex-wrap gap-2">
                    <button
                        v-for="filter in statusFilters"
                        :key="filter.value"
                        type="button"
                        class="min-h-[44px] px-3 text-sm rounded-md border transition duration-75"
                        :class="filters.status === filter.value
                            ? 'bg-gray-800 text-white border-gray-800'
                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                        :aria-pressed="filters.status === filter.value ? 'true' : 'false'"
                        @click="() => filterBy({ status: filter.value })"
                    >{{ filter.label }}</button>
                </div>
            </fieldset>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6">
            <div v-if="requests.data.length === 0" class="text-sm text-gray-600">
                Nothing waiting here.
            </div>

            <ul v-else class="space-y-4">
                <li
                    v-for="request in requests.data"
                    :key="request.id"
                    class="border-b border-gray-100 pb-4 last:border-b-0 last:pb-0"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2 text-xs text-gray-600">
                        <span v-if="typesByValue[request.type]" class="font-medium text-gray-800">{{ typesByValue[request.type].label }}</span>
                        <span>#{{ request.id }}</span>
                        <span>sent {{ request.createdAt }}</span>
                        <span v-if="rowFor(request).respondedAt">answered {{ formatDate(rowFor(request).respondedAt) }}<template v-if="request.respondedBy"> by {{ request.respondedBy.fullName ?? request.respondedBy.username }}</template></span>
                    </div>

                    <RequestBadge
                        :request="request"
                        class="!max-w-none"
                        @on-data="responded"
                        @refused="responded"
                        @alert="(data) => setAlertData(data)"
                    />

                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                        <span
                            v-if="rowFor(request).ownAgeVerification && request.status === 'PENDING'"
                            class="px-2 py-1 rounded-md bg-gray-50 border border-gray-200 text-gray-700 text-xs"
                        >This is your own submission, so another administrator needs to review it.</span>
                        <Link
                            v-if="typesByValue[request.type]?.page"
                            :href="route(typesByValue[request.type].page.route)"
                            class="inline-flex items-center min-h-[44px] text-blue-700 hover:text-blue-900 underline"
                        >{{ typesByValue[request.type].page.label }}</Link>
                    </div>
                </li>
            </ul>

            <Pagination :meta="requests.meta" @navigate="goToPage" />
        </div>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
