<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

// TT-7.3b-j/SCRUM-241: mirrors Admin/Payouts.vue's own dedicated-page-with-audit-table structure
// (TT-7.6e) -- a fresh page tree, not another tab on Organization/Show.vue, since this is its own
// read-only reporting surface, not an editable org-profile section.

const props = defineProps({
    organization: {
        type: Object,
        required: true,
    },
    financedTransactions: {
        type: Object,
        required: true,
    },
    retainerInvoices: {
        type: Object,
        required: true,
    },
})

const transactions = reactive({ data: [...props.financedTransactions.data], meta: props.financedTransactions.meta })
const loadingTransactionsPage = ref(false)

const invoices = reactive({ data: [...props.retainerInvoices.data], meta: props.retainerInvoices.meta })
const loadingInvoicesPage = ref(false)
const expandedInvoiceIds = ref([])

function formatMoney(minorUnitsAmount, currency) {
    if (minorUnitsAmount == null || !currency) return '--'

    return `${currency} ${(minorUnitsAmount / 100).toFixed(2)}`
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '--'
}

async function goToTransactionsPage(url) {
    if (!url) return
    loadingTransactionsPage.value = true

    await axios.get(url)
        .then((res) => {
            transactions.data = res.data.data
            transactions.meta = res.data.meta
        })
        .finally(() => {
            loadingTransactionsPage.value = false
        })
}

async function goToInvoicesPage(url) {
    if (!url) return
    loadingInvoicesPage.value = true

    await axios.get(url)
        .then((res) => {
            invoices.data = res.data.data
            invoices.meta = res.data.meta
        })
        .finally(() => {
            loadingInvoicesPage.value = false
        })
}

function toggleInvoice(invoiceId) {
    expandedInvoiceIds.value = expandedInvoiceIds.value.includes(invoiceId)
        ? expandedInvoiceIds.value.filter((id) => id !== invoiceId)
        : [...expandedInvoiceIds.value, invoiceId]
}

function invoiceStatusClass(status) {
    return {
        'text-green-700': status === 'SETTLED',
        'text-red-700': status === 'FAILED',
        'text-blue-700': status === 'PENDING',
        'text-gray-600': status === 'OPEN',
    }
}
</script>

<template>
    <Head title="Billing Reconciliation" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] mx-auto space-y-8">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-2xl font-bold text-gray-900">{{ organization.name }} &mdash; Billing Reconciliation</div>
                    <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                </div>
                <Link :href="route('organizations.dashboard', { organizationId: organization.id })" class="text-sm text-blue-600 hover:underline">back to dashboard</Link>
            </div>

            <div v-if="organization.isBillingSuspended" class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">
                <div class="font-semibold">Billing suspended</div>
                <div class="text-sm mt-1">
                    Retainer-covered members are blocked from further access until the outstanding invoice is settled.
                    Suspended since {{ formatDate(organization.billingSuspendedAt) }}.
                </div>
            </div>

            <!-- Pay-Per-Use Financed Transactions -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">Financed Sessions (Pay-Per-Use)</div>
                <div class="mt-1 text-sm text-gray-600">Every session/therapy this organization has directly charged its payment instrument for.</div>

                <div class="relative mt-4 overflow-x-auto">
                    <div v-if="loadingTransactionsPage" class="text-center py-4 text-sm text-gray-500">loading...</div>

                    <table v-else class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">Engagement</th>
                                <th class="py-2 pr-4">Counsellor</th>
                                <th class="py-2 pr-4">Total Charged</th>
                                <th class="py-2 pr-4">Counsellor Share</th>
                                <th class="py-2 pr-4">Platform Fee</th>
                                <th class="py-2 pr-4">Payout Status</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="transaction in transactions.data" :key="transaction.id" class="border-b border-gray-100">
                                <td class="py-2 pr-4">{{ transaction.subjectLabel ?? '--' }}</td>
                                <td class="py-2 pr-4">{{ transaction.counsellorName ?? '--' }}</td>
                                <td class="py-2 pr-4">{{ formatMoney(transaction.amount, transaction.currency) }}</td>
                                <td class="py-2 pr-4">{{ formatMoney(transaction.counsellorShare, transaction.currency) }}</td>
                                <td class="py-2 pr-4">{{ formatMoney(transaction.platformFee, transaction.currency) }}</td>
                                <td class="py-2 pr-4">{{ (transaction.payoutStatus ?? 'not yet earned').toLowerCase() }}</td>
                                <td class="py-2 pr-4">{{ transaction.status.toLowerCase() }}</td>
                                <td class="py-2 pr-4">{{ formatDate(transaction.createdAt) }}</td>
                            </tr>
                            <tr v-if="!transactions.data.length">
                                <td colspan="8" class="py-4 text-center text-gray-500">No pay-per-use financed sessions yet.</td>
                            </tr>
                        </tbody>
                    </table>

                    <Pagination :meta="transactions.meta" @navigate="goToTransactionsPage" />
                </div>
            </div>

            <!-- Retainer Invoices -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">Retainer Invoices</div>
                <div class="mt-1 text-sm text-gray-600">One row per settlement period. Expand a row to see the sessions it covers.</div>

                <div class="relative mt-4 overflow-x-auto">
                    <div v-if="loadingInvoicesPage" class="text-center py-4 text-sm text-gray-500">loading...</div>

                    <table v-else class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4"></th>
                                <th class="py-2 pr-4">Period</th>
                                <th class="py-2 pr-4">Amount</th>
                                <th class="py-2 pr-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="invoice in invoices.data" :key="invoice.id">
                                <tr class="border-b border-gray-100 cursor-pointer hover:bg-gray-50" @click="() => toggleInvoice(invoice.id)">
                                    <td class="py-2 pr-4 text-gray-400">{{ expandedInvoiceIds.includes(invoice.id) ? '&minus;' : '+' }}</td>
                                    <td class="py-2 pr-4">{{ formatDate(invoice.periodStart) }} &ndash; {{ formatDate(invoice.periodEnd) }}</td>
                                    <td class="py-2 pr-4">{{ formatMoney(invoice.amount ?? invoice.accruedAmount, invoice.currency) }}<span v-if="invoice.amount == null" class="text-xs text-gray-400 ml-1">(accrued so far)</span></td>
                                    <td class="py-2 pr-4 font-medium" :class="invoiceStatusClass(invoice.status)">{{ invoice.status.toLowerCase() }}</td>
                                </tr>
                                <tr v-if="expandedInvoiceIds.includes(invoice.id)" class="bg-gray-50">
                                    <td colspan="4" class="p-4">
                                        <table class="w-full text-xs text-left">
                                            <thead>
                                                <tr class="text-gray-500">
                                                    <th class="py-1 pr-4">Session</th>
                                                    <th class="py-1 pr-4">Counsellor</th>
                                                    <th class="py-1 pr-4">Net Amount</th>
                                                    <th class="py-1 pr-4">Fee Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="line in invoice.lines" :key="line.id">
                                                    <td class="py-1 pr-4">{{ line.sessionLabel ?? '--' }}</td>
                                                    <td class="py-1 pr-4">{{ line.counsellorName ?? '--' }}</td>
                                                    <td class="py-1 pr-4">{{ formatMoney(line.netAmount, line.currency) }}</td>
                                                    <td class="py-1 pr-4">{{ formatMoney(line.feeAmount, line.currency) }}</td>
                                                </tr>
                                                <tr v-if="!invoice.lines.length">
                                                    <td colspan="4" class="py-2 text-center text-gray-500">No sessions recorded for this period.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!invoices.data.length">
                                <td colspan="4" class="py-4 text-center text-gray-500">No retainer invoices yet.</td>
                            </tr>
                        </tbody>
                    </table>

                    <Pagination :meta="invoices.meta" @navigate="goToInvoicesPage" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
