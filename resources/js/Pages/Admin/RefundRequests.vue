<script setup>
import { ref, watch } from 'vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import axios from 'axios'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
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

// SCRUM-430: the shared admin tables.
const requestColumns = [
    { label: 'Client' },
    { label: 'Transaction' },
    { label: 'Reason' },
    { label: 'Requested' },
    { label: 'Actions', srOnly: true },
]
const failedColumns = [
    { label: 'Client' },
    { label: 'Amount' },
    { label: 'Why it failed' },
    { label: 'Failed' },
    { label: 'Reference' },
]

function clientName(item) {
    return item.from?.fullName ?? item.from?.username ?? '--'
}

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
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Refund Requests</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Clients awaiting a decision on their refund request, and refunds that failed. Approving does not itself move any
                    money -- that happens once the refund is actually processed.
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by the payer's name or username, or the payment reference" @search="searchFor" />

            <div class="space-y-4">
                <AdminTable :columns="requestColumns" caption="Pending refund requests" :empty="requests.length === 0" empty-text="No refund requests are currently pending.">
                    <AdminRow v-for="request in requests" :key="request.id">
                        <AdminCell label="Client"><span class="font-medium text-gray-900">{{ clientName(request) }}</span></AdminCell>
                        <AdminCell label="Transaction">
                            <template v-if="request.transaction">
                                {{ formatMoney(request.transaction.amount, request.transaction.currency) }}
                                <span v-if="request.transaction.subjectName" class="text-gray-500">({{ request.transaction.subjectName }})</span>
                            </template>
                            <span v-else class="text-gray-400">--</span>
                        </AdminCell>
                        <AdminCell label="Reason"><div class="md:max-w-[240px] text-gray-600">{{ request.reason ?? '--' }}</div></AdminCell>
                        <AdminCell label="Requested" half>{{ request.createdAt }}</AdminCell>
                        <AdminCell actions>
                            <div v-if="rejectingId === request.id" class="relative w-full md:min-w-[260px] text-left">
                                <FormLoader class="mx-auto" :show="respondingId === request.id" :text="'submitting'" />
                                <RefundRejectionReasonForm
                                    :request-id="request.id"
                                    :responding="respondingId === request.id"
                                    @cancel="cancelReject"
                                    @submit="(reason) => confirmReject(request, reason)"
                                />
                            </div>
                            <template v-else>
                                <AdminActionButton
                                    tone="primary"
                                    :disabled="respondingId === request.id"
                                    :aria-label="`Approve the refund for ${clientName(request)}`"
                                    @click="() => approve(request)"
                                >Approve</AdminActionButton>
                                <AdminActionButton
                                    tone="danger"
                                    :disabled="respondingId === request.id"
                                    :aria-label="`Reject the refund for ${clientName(request)}`"
                                    @click="() => startReject(request)"
                                >Reject</AdminActionButton>
                            </template>
                        </AdminCell>
                    </AdminRow>
                </AdminTable>

                <div v-if="loadingPage" class="text-center mt-4 text-sm text-gray-500">loading...</div>
                <Pagination v-else :meta="meta" @navigate="goToPage" />
            </div>

            <section aria-labelledby="failed-refunds-heading" class="space-y-4">
                <div>
                    <h2 id="failed-refunds-heading" class="text-lg font-semibold text-gray-900">Failed refunds</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Approved refunds the payment provider could not complete. Nothing retries these automatically --
                        follow each one up with the client and the provider.
                        <template v-if="failedRefundsTotal > failedRefunds.length">Showing the latest {{ failedRefunds.length }} of {{ failedRefundsTotal }}.</template>
                    </p>
                </div>

                <AdminTable :columns="failedColumns" caption="Failed refunds" :empty="failedRefunds.length === 0" empty-text="No refunds have failed.">
                    <AdminRow v-for="refund in failedRefunds" :key="refund.id">
                        <AdminCell label="Client"><span class="font-medium text-gray-900">{{ clientName(refund) }}</span></AdminCell>
                        <AdminCell label="Amount" half>{{ formatMoney(refund.amount, refund.currency) }}</AdminCell>
                        <AdminCell label="Why it failed"><div class="md:max-w-[280px] text-gray-600">{{ refund.failureReason ?? '--' }}</div></AdminCell>
                        <AdminCell label="Failed" half>{{ formatDate(refund.failedAt) }}</AdminCell>
                        <AdminCell label="Reference"><span class="text-gray-500 break-all">{{ refund.reference }}</span></AdminCell>
                    </AdminRow>
                </AdminTable>
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
