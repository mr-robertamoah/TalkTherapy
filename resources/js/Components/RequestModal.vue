<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';
import { watch } from 'vue';
import RequestBadge from './RequestBadge.vue';
import { computed } from 'vue';
import useAuth from '@/Composables/useAuth';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';

const { goToLogin } = useAuth()
const { alertData, clearAlertData, setAlertData } = useAlert()

const RequestStatus = {
    accepted: 'ACCEPTED',
    pending: 'PENDING',
    rejected: 'REJECTED',
}

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    }
})

const emits = defineEmits(['closeModal'])

const loading = ref(false)
const pages = ref({
    [RequestStatus.pending]: 1,
    [RequestStatus.accepted]: 1,
    [RequestStatus.rejected]: 1,
})
const pendingRequests = ref([])
const acceptedRequests = ref([])
const rejectedRequests = ref([])
const requestStatus = ref(RequestStatus.pending)

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
    acceptedRequests.value = []
    pendingRequests.value = []
    rejectedRequests.value = []
    requestStatus.value = RequestStatus.pending
})

function setPages(num) {
    pages.value[RequestStatus.accepted] = num
    pages.value[RequestStatus.rejected] = num
    pages.value[RequestStatus.pending] = num
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

    await axios
    .get(route('requests.get', {
        status: requestStatus.value,
        page: pages.value[requestStatus.value]
    }))
    .then((res) => {
        console.log(res)
        if (pages.value[requestStatus.value] > 1) {
            updateRequests(res.data.data)
            updatePage(res)
            return
        }

        addRequests(res.data.data)
        updatePage(res)
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

function addRequests(data) {
    if (requestStatus.value == RequestStatus.accepted) {
        acceptedRequests.value = [...data]
        return
    }
    
    if (requestStatus.value == RequestStatus.pending) {
        pendingRequests.value = [...data]
        return
    }

    rejectedRequests.value = [...data]
}

function updateRequests(data) {
    if (requestStatus.value == RequestStatus.accepted) {
        acceptedRequests.value = [...acceptedRequests.value, ...data]
        return
    }
    
    if (requestStatus.value == RequestStatus.pending) {
        pendingRequests.value = [...pendingRequests.value, ...data]
        return
    }

    rejectedRequests.value = [...rejectedRequests.value, ...data]
}

function updatePage(res) {
    if (res.data.links.next) pages.value[requestStatus.value] += 1
    else pages.value[requestStatus.value] = 0
}

const hasRequests = computed(() => {
    return (pendingRequests.value.length && requestStatus.value == RequestStatus.pending) || 
        (acceptedRequests.value.length && requestStatus.value == RequestStatus.accepted) ||
        (rejectedRequests.value.length && requestStatus.value == RequestStatus.rejected)
})

function removeFromPendingRequests(request) {
    pendingRequests.value = [...pendingRequests.value.filter((c) => c.id !== request.id)]
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

            <div class="flex justify-center gap-1 mb-4 bg-gray-100 rounded-lg p-1 w-fit mx-auto">
                <button
                    type="button"
                    @click="() => requestStatus = RequestStatus.pending"
                    class="min-w-[6rem] text-center px-4 py-1.5 text-sm rounded-md transition duration-75"
                    :class="requestStatus == RequestStatus.pending ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-200'"
                >Pending</button>
                <button
                    type="button"
                    @click="() => requestStatus = RequestStatus.accepted"
                    class="min-w-[6rem] text-center px-4 py-1.5 text-sm rounded-md transition duration-75"
                    :class="requestStatus == RequestStatus.accepted ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-200'"
                >Accepted</button>
                <button
                    type="button"
                    @click="() => requestStatus = RequestStatus.rejected"
                    class="min-w-[6rem] text-center px-4 py-1.5 text-sm rounded-md transition duration-75"
                    :class="requestStatus == RequestStatus.rejected ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-200'"
                >Rejected</button>
            </div>

            <div class="min-h-[10rem] max-h-[60vh] overflow-hidden overflow-y-auto p-4 bg-gray-50 rounded-lg">
                <div v-if="loading" class="p-3 text-center text-sm text-blue-700 bg-blue-50 border border-blue-200 rounded-lg mb-4">Loading {{ requestStatus.toLowerCase() }} requests...</div>
                <div v-if="hasRequests" class="space-y-3">
                    <template v-if="requestStatus == RequestStatus.pending">
                        <RequestBadge
                            v-for="request in pendingRequests"
                            :key="request.id"
                            :request="request"
                            class="mx-auto"
                            @on-data="(req) => {
                                removeFromPendingRequests(req)
                            }"
                            @alert="(alertData) => {
                                setAlert(alertData)
                            }"
                        />
                    </template>
                    <template v-if="requestStatus == RequestStatus.rejected">
                        <RequestBadge
                            v-for="request in rejectedRequests"
                            :key="request.id"
                            :request="request"
                            class="mx-auto"
                        />
                    </template>
                    <template v-if="requestStatus == RequestStatus.accepted">
                        <RequestBadge
                            v-for="request in acceptedRequests"
                            :key="request.id"
                            :request="request"
                            class="mx-auto"
                        />
                    </template>
                </div>
                <div v-else-if="!loading" class="flex justify-center items-center h-24 text-gray-500 text-sm">
                    <div class="text-center">
                        <div class="text-4xl mb-2">📭</div>
                        <div>No {{ requestStatus.toLowerCase() }} requests</div>
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