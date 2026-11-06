<script setup>
// SCRUM-387: the old /administrator "counsellors > verification requests" tab as its own page --
// counsellors' verification requests, pending ones by default, one status at a time (?status=, in
// the URL). Each opens the licence review modal, answered through requests.respond. The same
// requests are in "Requests to resolve" too.
import { Head, router } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import VerificationRequest from '@/Components/VerificationRequest.vue'
import useAlert from '@/Composables/useAlert'
import useEnums from '@/Composables/useEnums'
import { refreshAdminBadges } from '@/Composables/useAdminBadges'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    status: { type: String, required: true },
    statuses: { type: Array, required: true },
})

const { alertData, clearAlertData, setFailedAlertData } = useAlert()

const { RequestStatusLabels } = useEnums()
const filters = [
    ...props.statuses.map((value) => ({ value, label: RequestStatusLabels[value] ?? value })),
    { value: 'all', label: 'All' },
]

const requests = ref([])
const nextPage = ref(1)
const loading = ref(false)

async function getRequests() {
    if (!nextPage.value || loading.value) return

    const page = nextPage.value
    const query = { page }
    if (props.status !== 'all') {
        query.filterType = 'status'
        query.filterValue = props.status
    }

    loading.value = true
    await axios.get(route('admin.verification.requests', query))
        .then((res) => {
            requests.value = page > 1 ? [...requests.value, ...res.data.data] : [...res.data.data]
            nextPage.value = res.data?.links?.next ? page + 1 : 0
        })
        .catch(() => setFailedAlertData({ message: 'Something unfortunate happened. Please try again later.' }))
        .finally(() => {
            loading.value = false
        })
}

function filterBy(status) {
    router.get(route('administrator.counsellor_verification'), status === 'PENDING' ? {} : { status }, { preserveScroll: true })
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
        .catch((err) => setFailedAlertData({ message: err.response?.data?.error ?? 'Something unfortunate happened. Please try again later.' }))
}

onMounted(getRequests)
</script>

<template>
    <Head title="Counsellor verification" />

    <div class="my-8 w-full max-w-5xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Counsellor verification</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">Counsellors asking to be verified. Open one to see their licences, then accept or reject it.</p>
        </div>

        <div class="flex flex-wrap gap-2" role="group" aria-label="Status">
            <button
                v-for="filter in filters"
                :key="filter.value"
                type="button"
                class="min-h-[44px] px-3 text-sm rounded-md border transition duration-75"
                :class="status === filter.value
                    ? 'bg-gray-800 text-white border-gray-800'
                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                :aria-pressed="status === filter.value ? 'true' : 'false'"
                @click="() => filterBy(filter.value)"
            >{{ filter.label }}</button>
        </div>

        <div class="relative bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6 space-y-3">
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
