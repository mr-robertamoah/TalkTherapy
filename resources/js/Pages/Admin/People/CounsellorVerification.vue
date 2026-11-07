<script setup>
// SCRUM-387: the old /administrator "counsellors > verification requests" tab as its own page --
// counsellors' verification requests, pending ones by default, one status at a time (?status=, in
// the URL). Each opens the licence review modal, answered through requests.respond. The same
// requests are in "Requests to resolve" too.
// SCRUM-31: ?view=renewals lists verifications ending soon, ended or revoked instead, each with its
// history and (while current) a revoke action.
import { Head, router } from '@inertiajs/vue3'
import { onMounted, ref, watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import VerificationRequest from '@/Components/VerificationRequest.vue'
import Avatar from '@/Components/Avatar.vue'
import CounsellorVerificationPanel from '@/Components/Admin/CounsellorVerificationPanel.vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import useAlert from '@/Composables/useAlert'
import useEnums from '@/Composables/useEnums'
import { refreshAdminBadges } from '@/Composables/useAdminBadges'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    status: { type: String, required: true },
    statuses: { type: Array, required: true },
    view: { type: String, default: 'requests' },
    renewalWindowDays: { type: Number, default: 30 },
    renewalFilter: { type: String, default: 'due' },
    search: { type: String, default: '' },
})

const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()

const { RequestStatusLabels } = useEnums()
const filters = [
    ...props.statuses.map((value) => ({ value, label: RequestStatusLabels[value] ?? value })),
    { value: 'all', label: 'All' },
]

const requests = ref([])
const nextPage = ref(1)
const loading = ref(false)

// A newer load (a new search or filter) wins over one still on its way (review): "load more" waits,
// a first page always goes, and only the latest answer is shown.
let latestRequests = 0
let latestRenewals = 0

async function getRequests() {
    if (!nextPage.value || (loading.value && nextPage.value > 1)) return

    const ticket = ++latestRequests
    const page = nextPage.value
    const query = { page, ...(props.search ? { search: props.search } : {}) }
    if (props.status !== 'all') {
        query.filterType = 'status'
        query.filterValue = props.status
    }

    loading.value = true
    await axios.get(route('admin.verification.requests', query))
        .then((res) => {
            if (ticket !== latestRequests) return
            requests.value = page > 1 ? [...requests.value, ...res.data.data] : [...res.data.data]
            nextPage.value = res.data?.links?.next ? page + 1 : 0
        })
        .catch(() => setFailedAlertData({ message: 'Something unfortunate happened. Please try again later.' }))
        .finally(() => {
            if (ticket === latestRequests) loading.value = false
        })
}

// From the first page again (what's shown is stale).
function reloadRequests() {
    nextPage.value = 1
    getRequests()
}

function filterBy(status) {
    searchPage('administrator.counsellor_verification', props.search, { status: status === 'PENDING' ? undefined : status }, false)
}

function showRenewals(filter = 'due') {
    searchPage('administrator.counsellor_verification', props.search, { view: 'renewals', filter: filter === 'ended' ? filter : undefined }, false)
}

// SCRUM-31: the renewals list.
const renewals = ref([])
const renewalsNextPage = ref(1)
const renewalsLoading = ref(false)

async function getRenewals() {
    if (!renewalsNextPage.value || (renewalsLoading.value && renewalsNextPage.value > 1)) return

    const ticket = ++latestRenewals
    const page = renewalsNextPage.value
    renewalsLoading.value = true
    await axios.get(route('admin.counsellors.verification.renewals', { page, filter: props.renewalFilter, ...(props.search ? { search: props.search } : {}) }))
        .then((res) => {
            if (ticket !== latestRenewals) return
            renewals.value = page > 1 ? [...renewals.value, ...res.data.data] : [...res.data.data]
            renewalsNextPage.value = res.data?.links?.next ? page + 1 : 0
        })
        .catch(() => setFailedAlertData({ message: 'Something unfortunate happened. Please try again later.' }))
        .finally(() => { if (ticket === latestRenewals) renewalsLoading.value = false })
}

function respond(requestId, response) {
    axios.post(route('requests.respond', { requestId }), { response: response.toUpperCase() })
        .then((res) => {
            if (res.data.error) {
                setFailedAlertData({ message: res.data.error })
                return
            }

            requests.value.splice(requests.value.findIndex((request) => request.id == res.data.request.id), 1, res.data.request)
            // The sidebar's badge (the server cleared its cached counts).
            refreshAdminBadges({ force: true })
        })
        .catch((err) => {
            setFailedAlertData({ message: err.response?.data?.error ?? 'Something unfortunate happened. Please try again later.' })

            // SCRUM-388: refused (e.g. answered in another tab) -- show what's true now.
            if (err.response?.status == 422) {
                reloadRequests()
                refreshAdminBadges({ force: true })
            }
        })
}

onMounted(() => props.view === 'renewals' ? getRenewals() : getRequests())

// SCRUM-390: a new search (in the URL) lists from the first page again.
function searchFor(term) {
    const keep = props.view === 'renewals'
        ? { view: 'renewals', filter: props.renewalFilter === 'ended' ? 'ended' : undefined }
        : { status: props.status === 'PENDING' ? undefined : props.status }
    searchPage('administrator.counsellor_verification', term, keep)
}

watch(() => props.search, () => {
    if (props.view === 'renewals') {
        renewalsNextPage.value = 1
        getRenewals()
    } else {
        reloadRequests()
    }
})
</script>

<template>
    <Head title="Counsellor verification" />

    <div class="my-8 w-full max-w-5xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Counsellor verification</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p v-if="view === 'renewals'" class="mt-2 text-sm text-gray-600">Verifications ending in the next {{ renewalWindowDays }} days, or ones that have ended or were revoked. Counsellors are reminded to re-verify; their requests appear under the request filters.</p>
            <p v-else class="mt-2 text-sm text-gray-600">Counsellors asking to be verified. Open one to see their licences, then accept or reject it.</p>
        </div>

        <AdminSearchBox :model-value="search" label="Search by the counsellor's name or username" @search="searchFor" />

        <div class="flex flex-wrap gap-2" role="group" aria-label="Status">
            <button
                v-for="filter in filters"
                :key="filter.value"
                type="button"
                class="min-h-[44px] px-3 text-sm rounded-md border transition duration-75"
                :class="view !== 'renewals' && status === filter.value
                    ? 'bg-gray-800 text-white border-gray-800'
                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                :aria-pressed="view !== 'renewals' && status === filter.value ? 'true' : 'false'"
                @click="() => filterBy(filter.value)"
            >{{ filter.label }}</button>
            <button
                type="button"
                class="min-h-[44px] px-3 text-sm rounded-md border transition duration-75"
                :class="view === 'renewals'
                    ? 'bg-gray-800 text-white border-gray-800'
                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                :aria-pressed="view === 'renewals' ? 'true' : 'false'"
                @click="() => showRenewals()"
            >Renewals</button>
        </div>

        <div v-if="view === 'renewals'" class="flex flex-wrap gap-2" role="group" aria-label="Renewals">
            <button
                v-for="option in [{ value: 'due', label: 'Ending soon' }, { value: 'ended', label: 'Ended or revoked' }]"
                :key="option.value"
                type="button"
                class="min-h-[44px] px-3 text-sm rounded-md border transition duration-75"
                :class="renewalFilter === option.value
                    ? 'bg-blue-700 text-white border-blue-700'
                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                :aria-pressed="renewalFilter === option.value ? 'true' : 'false'"
                @click="() => showRenewals(option.value)"
            >{{ option.label }}</button>
        </div>

        <div v-if="view === 'renewals'" class="relative bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6 space-y-3">
            <FormLoader class="mx-auto" :show="renewalsLoading" :text="'getting renewals'" />

            <div v-if="!renewalsLoading && renewals.length === 0" class="text-sm text-gray-600">{{ renewalFilter === 'ended' ? 'No verifications have ended or been revoked.' : `No verifications end in the next ${renewalWindowDays} days.` }}</div>

            <div v-for="counsellor in renewals" :key="counsellor.id" class="border border-gray-100 rounded-lg p-3 space-y-2">
                <div class="flex flex-wrap items-center gap-3">
                    <Avatar :size="40" :src="counsellor.avatar ?? ''" class="shrink-0" />
                    <div class="min-w-0">
                        <div class="font-medium text-gray-900 capitalize truncate">{{ counsellor.name }}</div>
                        <div class="text-xs text-gray-600">{{ counsellor.username ? `@${counsellor.username}` : '' }}</div>
                    </div>
                    <span v-if="counsellor.hasPendingRequest" class="ml-auto text-xs font-medium text-blue-800 bg-blue-50 border border-blue-200 rounded-full px-2 py-0.5">Re-verification asked for</span>
                </div>
                <CounsellorVerificationPanel
                    :counsellor-id="counsellor.id"
                    :verification="counsellor.verification"
                    @alert="(data) => setAlertData({ time: 5000, ...data })"
                />
            </div>

            <div v-if="renewalsNextPage && renewals.length && !renewalsLoading" class="flex justify-center pt-2">
                <PrimaryButton @click="getRenewals">get more</PrimaryButton>
            </div>
        </div>

        <div v-else class="relative bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6 space-y-3">
            <FormLoader class="mx-auto" :show="loading" :text="'getting verification requests'" />

            <div v-if="!loading && requests.length === 0" class="text-sm text-gray-600">Nothing here.</div>

            <div class="flex flex-wrap gap-3">
                <VerificationRequest
                    v-for="request in requests"
                    :key="request.id"
                    :request="request"
                    @on-response="(response) => respond(request.id, response)"
                />
            </div>

            <div v-if="nextPage && requests.length && !loading" class="flex justify-center pt-2">
                <PrimaryButton @click="getRequests">get more</PrimaryButton>
            </div>
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
