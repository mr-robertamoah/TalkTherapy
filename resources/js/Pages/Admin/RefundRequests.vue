<script setup>
import { ref, watch } from 'vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import axios from 'axios'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import FormLoader from '@/Components/FormLoader.vue'
import RefundRejectionReasonForm from '@/Components/RefundRejectionReasonForm.vue'
import useAlert from '@/Composables/useAlert'
import { refreshAdminBadges } from '@/Composables/useAdminBadges'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Head } from '@inertiajs/vue3'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// TT-7.7c/SCRUM-251: a dedicated page, not another Admin.vue dispatch-table tab (same reasoning
// as Payouts.vue/OrganizationBilling.vue's own top comments). Platform-admin only. Approve/reject
// post directly to the existing generic requests.respond endpoint via raw axios (mirrors
// RequestQueueSection.vue's own precedent) -- that endpoint returns a plain JSON response, not an
// Inertia-compatible one, so useForm() isn't a fit here the way it is on OrganizationBilling.vue.

const props = defineProps({
    search: { type: String, default: '' },
    refundRequests: { type: Object, required: true },
    // SCRUM-387: refunds the provider failed (AdminFailedRefundResource), newest first, and how
    // many there are in all. Nothing retries one, so they are only listed.
    failedRefunds: { type: Array, default: () => [] },
    failedRefundsTotal: { type: Number, default: 0 },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const requests = ref([...props.refundRequests.data])
const meta = ref(props.refundRequests.meta)
const loadingPage = ref(false)
const respondingId = ref(null)
const rejectingId = ref(null)

function formatMoney(minorUnitsAmount, currency) {
    if (minorUnitsAmount == null || !currency) return '--'

    return `${currency} ${(minorUnitsAmount / 100).toFixed(2)}`
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}

// Answered: off this list, and the sidebar's badge drops (the server cleared its cached counts).
function removeFromQueue(requestId) {
    requests.value = requests.value.filter((r) => r.id !== requestId)
    refreshAdminBadges({ force: true })
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
}

function cancelReject() {
    rejectingId.value = null
}

async function confirmReject(request, reason) {
    respondingId.value = request.id

    try {
        await axios.post(route('requests.respond', { requestId: request.id }), {
            response: 'rejected',
            reason,
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

// A new search's list replaces this page's copy of it.
watch(() => props.refundRequests, (page) => { requests.value = [...page.data] })

// SCRUM-390: the page's search, kept in the URL with its other filters.
function searchFor(term) {
    searchPage('administrator.refund_requests', term, {})
}
</script>

<template>
    <Head title="Refund Requests" />

    <div>
        <div class="my-8 w-full max-w-6xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Refund Requests</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Clients awaiting a decision on their refund request, and refunds that failed. Approving does not itself move any
                    money -- that happens once the refund is actually processed.
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by the payer's name or username, or the payment reference" @search="searchFor" />

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
                                        <RefundRejectionReasonForm
                                            :request-id="request.id"
                                            :responding="respondingId === request.id"
                                            @cancel="cancelReject"
                                            @submit="(reason) => confirmReject(request, reason)"
                                        />
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

            <section aria-labelledby="failed-refunds-heading" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h2 id="failed-refunds-heading" class="text-lg font-semibold text-gray-900">Failed refunds</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Approved refunds the payment provider could not complete. Nothing retries these automatically --
                    follow each one up with the client and the provider.
                    <template v-if="failedRefundsTotal > failedRefunds.length">Showing the latest {{ failedRefunds.length }} of {{ failedRefundsTotal }}.</template>
                </p>

                <div v-if="failedRefunds.length === 0" class="mt-4 text-sm text-gray-500 italic">
                    No refunds have failed.
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">Client</th>
                                <th class="py-2 pr-4">Amount</th>
                                <th class="py-2 pr-4">Why it failed</th>
                                <th class="py-2 pr-4">Failed</th>
                                <th class="py-2 pr-4">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="refund in failedRefunds" :key="refund.id" class="border-b border-gray-100 align-top">
                                <td class="py-2 pr-4 font-medium">{{ refund.from?.fullName ?? refund.from?.username ?? '--' }}</td>
                                <td class="py-2 pr-4">{{ formatMoney(refund.amount, refund.currency) }}</td>
                                <td class="py-2 pr-4 max-w-[280px] text-gray-600">{{ refund.failureReason ?? '--' }}</td>
                                <td class="py-2 pr-4">{{ formatDate(refund.failedAt) }}</td>
                                <td class="py-2 pr-4 text-gray-500 break-all">{{ refund.reference }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
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
