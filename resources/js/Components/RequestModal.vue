<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';
import { watch } from 'vue';
import RequestBadge from './RequestBadge.vue';
import { computed } from 'vue';
import useAuth from '@/Composables/useAuth';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import useEnums from '@/Composables/useEnums';

const { goToLogin } = useAuth()
const { alertData, clearAlertData, setAlertData } = useAlert()
const { RequestTypeEnum } = useEnums()

const RequestStatus = {
    accepted: 'ACCEPTED',
    pending: 'PENDING',
    rejected: 'REJECTED',
    // SCRUM-73 QA: closed without an answer, e.g. a safeguarding request after the member left.
    closed: 'INCONSEQUENCIAL',
}

// One tab per status, in this order.
const tabs = [
    { status: RequestStatus.pending, label: 'Pending' },
    { status: RequestStatus.accepted, label: 'Accepted' },
    { status: RequestStatus.rejected, label: 'Rejected' },
    { status: RequestStatus.closed, label: 'Closed' },
]

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    }
})

const emits = defineEmits(['closeModal'])

const loading = ref(false)
const pages = ref(Object.fromEntries(tabs.map((tab) => [tab.status, 1])))
const requests = ref(emptyLists())
const requestStatus = ref(RequestStatus.pending)

function emptyLists() {
    return Object.fromEntries(tabs.map((tab) => [tab.status, []]))
}

watch(() => requestStatus.value, () => {
    if (pages.value[requestStatus.value] == 1) {
        debouncedGet()
    }
})
watch(() => props.show, () => {
    if (props.show) {
        pages.value[requestStatus.value] = 1
        debouncedGet()
        return
    }

    setPages(1)
    requests.value = emptyLists()
    requestStatus.value = RequestStatus.pending
})

function setPages(num) {
    tabs.forEach((tab) => { pages.value[tab.status] = num })
}

function closeModal() {
    emits('closeModal')
}

function setAlert(alertData) {
    setAlertData({
        ...alertData
    })
}

async function getRequests() {
    loading.value = true
    const status = requestStatus.value

    await axios
    .get(route('requests.get', {
        status,
        page: pages.value[status]
    }))
    .then((res) => {
        // Page 1 replaces the list; later pages add to it.
        requests.value[status] = pages.value[status] > 1
            ? [...requests.value[status], ...res.data.data]
            : [...res.data.data]
        updatePage(status, res)
    })
    .catch((err) => {
        console.log(err)
        goToLogin(err)
    })
    .finally(() => {
        loading.value = false
    })
}

const debouncedGet = () => {
    getRequests()
}

function updatePage(status, res) {
    if (res.data.links.next) pages.value[status] += 1
    else pages.value[status] = 0
}

const shownRequests = computed(() => requests.value[requestStatus.value] ?? [])

const hasRequests = computed(() => shownRequests.value.length > 0)

const shownStatusLabel = computed(() => tabs.find((tab) => tab.status == requestStatus.value)?.label.toLowerCase())

function removeFromPendingRequests(request) {
    requests.value[RequestStatus.pending] = requests.value[RequestStatus.pending].filter((c) => c.id !== request.id)
}

// SCRUM-381: accepting a safeguarding exception request closes the minor's other pending ones on the
// server, so reload the pending list rather than leave them showing as Pending.
function respondedTo(request) {
    removeFromPendingRequests(request)

    if (request?.type == RequestTypeEnum.safeguardingException && request?.status == RequestStatus.accepted) {
        pages.value[RequestStatus.pending] = 1
        getRequests()
    }
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-6">
            <div class="w-full mb-6">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">User Requests</h2>
                    <div class="w-16 h-1 bg-blue-600 mx-auto rounded-full"></div>
                </div>
            </div>

            <div class="flex justify-center gap-1 mb-4 bg-gray-100 rounded-lg p-1 w-fit max-w-full mx-auto overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.status"
                    type="button"
                    @click="() => requestStatus = tab.status"
                    class="shrink-0 min-w-[6rem] text-center px-4 py-1.5 text-sm rounded-md transition duration-75"
                    :class="requestStatus == tab.status ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-200'"
                >{{ tab.label }}</button>
            </div>

            <div class="min-h-[10rem] max-h-[60vh] overflow-hidden overflow-y-auto p-4 bg-gray-50 rounded-lg">
                <div v-if="loading" class="p-3 text-center text-sm text-blue-700 bg-blue-50 border border-blue-200 rounded-lg mb-4">Loading {{ shownStatusLabel }} requests...</div>
                <div v-if="hasRequests" class="space-y-3">
                    <template v-if="requestStatus == RequestStatus.pending">
                        <RequestBadge
                            v-for="request in shownRequests"
                            :key="request.id"
                            :request="request"
                            class="mx-auto"
                            @on-data="respondedTo"
                            @alert="(alertData) => {
                                setAlert(alertData)
                            }"
                        />
                    </template>
                    <template v-else>
                        <RequestBadge
                            v-for="request in shownRequests"
                            :key="request.id"
                            :request="request"
                            class="mx-auto"
                        />
                    </template>
                </div>
                <div v-else-if="!loading" class="flex justify-center items-center h-24 text-gray-500 text-sm">
                    <div class="text-center">
                        <div class="text-4xl mb-2">📭</div>
                        <div>No {{ shownStatusLabel }} requests</div>
                    </div>
                </div>

                <div v-if="pages[requestStatus] && !loading" @click="debouncedGet" title="get more requests" class="mt-4 flex justify-center text-gray-500 text-sm cursor-pointer hover:text-gray-700">
                    <div class="text-lg">...</div>
                </div>
            </div>
        </div>
    </Modal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>