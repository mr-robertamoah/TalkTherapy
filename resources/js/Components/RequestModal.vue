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
    .get(`requests?status=${requestStatus.value}&page=${pages.value[requestStatus.value]}`)
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
        <div class="p-4">
            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >User Requests</div>
                <hr>
            </div>

            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <div class="flex my-2 justify-between items-center mx-auto w-[90%]">
                    <div
                        @click="() => {
                            requestStatus = RequestStatus.pending
                        }"
                        class="min-w-[25%] mx-auto text-center p-2 cursor-pointer rounded transition duration-75"
                        :class="[requestStatus == RequestStatus.pending ? ' hover:bg-stone-200 hover:text-stone-600 bg-stone-600 text-stone-200' : 'bg-gray-200 text-gray-600 hover:bg-gray-600 hover:text-gray-200']"
                    >Pending</div>
                    <div
                        @click="() => {
                            requestStatus = RequestStatus.accepted
                        }"
                        class="min-w-[25%] mx-auto text-center p-2 cursor-pointer rounded transition duration-75"
                        :class="[requestStatus == RequestStatus.accepted ? ' hover:bg-stone-200 hover:text-stone-600 bg-stone-600 text-stone-200' : 'bg-gray-200 text-gray-600 hover:bg-gray-600 hover:text-gray-200']"
                    >Accepted</div>
                    <div
                        @click="() => {
                            requestStatus = RequestStatus.rejected
                        }"
                        class="min-w-[25%] mx-auto text-center p-2 cursor-pointer rounded transition duration-75"
                        :class="[requestStatus == RequestStatus.rejected ? ' hover:bg-stone-200 hover:text-stone-600 bg-stone-600 text-stone-200' : 'bg-gray-200 text-gray-600 hover:bg-gray-600 hover:text-gray-200']"
                    >Rejected</div>
                </div>
                <hr class="mt-4">
                <div class="min-h-[30vh] max-h-[80vh] overflow-hidden overflow-y-auto p-2 flex justify-center items-center flex-col">
                    <div v-if="loading" class="p-2 text-center lowercase my-2 text-green-300 transition duration-100 rounded mx-auto w-[90%] bg-green-700">getting {{ requestStatus }} ...</div>
                    <div v-if="hasRequests" class="h-full w-full flex flex-col items-center">
                        <template v-if="requestStatus == RequestStatus.pending">
                            <RequestBadge
                                class="mb-2"
                                v-for="request in pendingRequests"
                                :key="request.id"
                                :request="request"
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
                                class="mb-2"
                                v-for="request in rejectedRequests"
                                :key="request.id"
                                :request="request"
                            />
                        </template>
                        <template v-if="requestStatus == RequestStatus.accepted">
                            <RequestBadge
                                class="mb-2"
                                v-for="request in acceptedRequests"
                                :key="request.id"
                                :request="request"
                            />
                        </template>
                    </div>
                    <div v-else-if="!loading" class="flex justify-center items-center h-full text-gray-600 text-sm">
                        <div class="lowercase">no {{ requestStatus }} requests</div>
                    </div>

                    <div v-if="pages[requestStatus] && !loading" @click="debouncedGet" title="get more requests" class="mt-6 mb-4 p-4 flex justify-center items-center h-full text-gray-600 text-sm cursor-pointer">
                        <div class="text-gray-600 text-lg cursor-pointer p-2">...</div>
                    </div>
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