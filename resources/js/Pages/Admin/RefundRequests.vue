<script setup>
import { ref } from 'vue'
import axios from 'axios'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextBox from '@/Components/TextBox.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import FormLoader from '@/Components/FormLoader.vue'
import useAlert from '@/Composables/useAlert'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head } from '@inertiajs/vue3'

// TT-7.7c/SCRUM-251: a dedicated page, not another Admin.vue dispatch-table tab (same reasoning
// as Payouts.vue/OrganizationBilling.vue's own top comments). Platform-admin only. Approve/reject
// post directly to the existing generic requests.respond endpoint via raw axios (mirrors
// RequestQueueSection.vue's own precedent) -- that endpoint returns a plain JSON response, not an
// Inertia-compatible one, so useForm() isn't a fit here the way it is on OrganizationBilling.vue.

const props = defineProps({
    refundRequests: { type: Object, required: true },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const requests = ref([...props.refundRequests.data])
const meta = ref(props.refundRequests.meta)
const loadingPage = ref(false)
const respondingId = ref(null)
const rejectingId = ref(null)
const rejectionReason = ref('')
const rejectionReasonError = ref('')

function formatMoney(minorUnitsAmount, currency) {
    if (minorUnitsAmount == null || !currency) return '--'

    return `${currency} ${(minorUnitsAmount / 100).toFixed(2)}`
}

function removeFromQueue(requestId) {
    requests.value = requests.value.filter((r) => r.id !== requestId)
}

async function approve(request) {
    respondingId.value = request.id

    try {
        await axios.post(route('requests.respond', { requestId: request.id }), { response: 'accepted' })
        setSuccessAlertData({ message: 'Refund request approved.' })
        removeFromQueue(request.id)
    } catch (err) {
        setFailedAlertData({ message: err.response?.data?.error ?? 'Could not approve the refund request.' })
    } finally {
        respondingId.value = null
    }
}

function startReject(request) {
    rejectingId.value = request.id
    rejectionReason.value = ''
    rejectionReasonError.value = ''
}

function cancelReject() {
    rejectingId.value = null
    rejectionReason.value = ''
    rejectionReasonError.value = ''
}

async function confirmReject(request) {
    rejectionReasonError.value = ''

    if (rejectionReason.value.trim().length < 10) {
        rejectionReasonError.value = 'Please provide at least 10 characters explaining why this request is being declined.'
        return
    }

    respondingId.value = request.id

    try {
        await axios.post(route('requests.respond', { requestId: request.id }), {
            response: 'rejected',
            reason: rejectionReason.value,
        })
        setSuccessAlertData({ message: 'Refund request rejected.' })
        removeFromQueue(request.id)
        cancelReject()
    } catch (err) {
        setFailedAlertData({ message: err.response?.data?.error ?? 'Could not reject the refund request.' })
    } finally {
        respondingId.value = null
    }
}

async function goToPage(url) {
    if (!url) return

    loadingPage.value = true
    await axios.get(url)
        .then((res) => {
            requests.value = [...res.data.data]
            meta.value = res.data.meta
        })
        .finally(() => {
            loadingPage.value = false
        })
}
</script>

<template>
    <Head title="Refund Requests" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] mx-auto space-y-8">
            <div>
                <div class="text-2xl font-bold text-gray-900">Refund Requests</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Clients awaiting a decision on their refund request. Approving does not itself move any
                    money -- that happens once the refund is actually processed.
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div v-if="requests.length === 0" class="text-sm text-gray-500 italic">
                    No refund requests are currently pending.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">Client</th>
                                <th class="py-2 pr-4">Transaction</th>
                                <th class="py-2 pr-4">Reason</th>
                                <th class="py-2 pr-4">Requested</th>
                                <th class="py-2 pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="request in requests" :key="request.id" class="border-b border-gray-100 align-top">
                                <td class="py-2 pr-4 font-medium">{{ request.from?.fullName ?? request.from?.username ?? '--' }}</td>
                                <td class="py-2 pr-4">
                                    <template v-if="request.transaction">
                                        {{ formatMoney(request.transaction.amount, request.transaction.currency) }}
                                        <span v-if="request.transaction.subjectName" class="text-gray-500">({{ request.transaction.subjectName }})</span>
                                    </template>
                                    <span v-else class="text-gray-400">--</span>
                                </td>
                                <td class="py-2 pr-4 max-w-[240px] text-gray-600">{{ request.reason ?? '--' }}</td>
                                <td class="py-2 pr-4">{{ request.createdAt }}</td>
                                <td class="py-2 pr-4 min-w-[220px]">
                                    <template v-if="rejectingId === request.id">
                                        <FormLoader class="mx-auto" :show="respondingId === request.id" :text="'submitting'" />
                                        <InputLabel :for="`reject_reason_${request.id}`" value="Why is this being declined?" />
                                        <TextBox :id="`reject_reason_${request.id}`" v-model="rejectionReason" class="mt-1 block w-full" rows="2" />
                                        <InputError :message="rejectionReasonError" class="mt-1" />
                                        <div class="mt-2 flex gap-2">
                                            <DangerButton :disabled="respondingId === request.id" @click="() => confirmReject(request)">confirm reject</DangerButton>
                                            <SecondaryButton :disabled="respondingId === request.id" @click="cancelReject">cancel</SecondaryButton>
                                        </div>
                                    </template>
                                    <div v-else class="flex items-center space-x-2">
                                        <PrimaryButton :disabled="respondingId === request.id" @click="() => approve(request)">approve</PrimaryButton>
                                        <DangerButton :disabled="respondingId === request.id" @click="() => startReject(request)">reject</DangerButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="loadingPage" class="text-center mt-4 text-sm text-gray-500">loading...</div>
                <Pagination v-else :meta="meta" @navigate="goToPage" />
            </div>
        </div>
    </AuthenticatedLayout>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
