<script setup>
import Alert from '@/Components/Alert.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import useAlert from '@/Composables/useAlert';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

// TT-7.3b-followup/SCRUM-245: the manual "resolve this" surface SCRUM-238 deliberately left
// unbuilt -- a dedicated page rather than another Admin.vue dispatch-table tab, same reasoning as
// Payouts.vue's own top comment. Platform-admin only: retrying a failed settlement and lifting a
// suspension are both trust decisions made by staff once they've confirmed things out-of-band, not
// self-service for the org's own admin.

const props = defineProps({
    organizations: { type: Object, required: true },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const retryForm = useForm({})
const liftForm = useForm({})

function formatMoney(minorUnitsAmount, currency) {
    if (minorUnitsAmount == null || !currency) return '--'

    return `${currency} ${(minorUnitsAmount / 100).toFixed(2)}`
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '--'
}

function retrySettlement(invoiceId) {
    retryForm.post(route('admin.organization_invoices.retry_settlement', { organizationInvoiceId: invoiceId }), {
        onSuccess: () => setSuccessAlertData({ message: 'Settlement retry started.' }),
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
    })
}

function liftSuspension(organizationId) {
    liftForm.post(route('admin.organizations.lift_billing_suspension', { organizationId }), {
        onSuccess: () => setSuccessAlertData({ message: 'Billing suspension lifted.' }),
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
    })
}

// No separate JSON list endpoint exists for this (low-volume, exception-only) list -- a page
// number is just a full Inertia visit back to this same route, unlike Reconciliation.vue's own
// axios-based "load more" against a dedicated JSON endpoint.
function goToPage(url) {
    if (!url) return
    router.visit(url, { preserveScroll: true })
}
</script>

<template>
    <Head title="Organization Billing" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] mx-auto space-y-8">
            <div>
                <div class="text-2xl font-bold text-gray-900">Organization Billing</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Organizations currently suspended for a failed retainer invoice settlement. Retrying a
                    settlement never automatically lifts a suspension -- review the organization's standing
                    first.
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div v-if="organizations.data.length === 0" class="text-sm text-gray-500 italic">
                    No organizations are currently billing-suspended.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">Organization</th>
                                <th class="py-2 pr-4">Suspended Since</th>
                                <th class="py-2 pr-4">Reason</th>
                                <th class="py-2 pr-4">Latest Failed Invoice</th>
                                <th class="py-2 pr-4">Payment Method</th>
                                <th class="py-2 pr-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="organization in organizations.data" :key="organization.id" class="border-b border-gray-100 align-top">
                                <td class="py-2 pr-4 font-medium">{{ organization.name }}</td>
                                <td class="py-2 pr-4">{{ formatDate(organization.billingSuspendedAt) }}</td>
                                <td class="py-2 pr-4 max-w-[240px] text-gray-600">{{ organization.billingSuspensionReason ?? '--' }}</td>
                                <td class="py-2 pr-4">
                                    <template v-if="organization.latestFailedInvoice">
                                        {{ formatDate(organization.latestFailedInvoice.periodStart) }} &ndash;
                                        {{ formatDate(organization.latestFailedInvoice.periodEnd) }}
                                        ({{ formatMoney(organization.latestFailedInvoice.amount, organization.latestFailedInvoice.currency) }})
                                    </template>
                                    <span v-else class="text-gray-400">none</span>
                                </td>
                                <td class="py-2 pr-4">
                                    <span :class="organization.hasPaymentInstrument ? 'text-green-700' : 'text-red-700'">
                                        {{ organization.hasPaymentInstrument ? 'on file' : 'missing' }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 space-y-2">
                                    <PrimaryButton
                                        v-if="organization.latestFailedInvoice"
                                        :disabled="retryForm.processing"
                                        :class="{ 'opacity-25': retryForm.processing }"
                                        @click="retrySettlement(organization.latestFailedInvoice.id)"
                                    >retry settlement</PrimaryButton>
                                    <PrimaryButton
                                        :disabled="liftForm.processing"
                                        :class="{ 'opacity-25': liftForm.processing }"
                                        @click="liftSuspension(organization.id)"
                                    >lift suspension</PrimaryButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :meta="organizations.meta" @navigate="goToPage" />
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
