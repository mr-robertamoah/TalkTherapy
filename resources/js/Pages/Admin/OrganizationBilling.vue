<script setup>
import Alert from '@/Components/Alert.vue';
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import Pagination from '@/Components/Pagination.vue';
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import useAlert from '@/Composables/useAlert';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout });

// TT-7.3b-followup/SCRUM-245: the manual "resolve this" surface SCRUM-238 deliberately left
// unbuilt -- a dedicated page rather than another Admin.vue dispatch-table tab, same reasoning as
// Payouts.vue's own top comment. Platform-admin only: retrying a failed settlement and lifting a
// suspension are both trust decisions made by staff once they've confirmed things out-of-band, not
// self-service for the org's own admin.

const props = defineProps({
    search: { type: String, default: '' },
    organizations: { type: Object, required: true },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

// SCRUM-430: the shared admin table.
const columns = [
    { label: 'Organization' },
    { label: 'Suspended since' },
    { label: 'Reason' },
    { label: 'Latest failed invoice' },
    { label: 'Payment method' },
    { label: 'Actions', srOnly: true },
]

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

// SCRUM-390: the page's search, kept in the URL with its other filters.
function searchFor(term) {
    searchPage('administrator.organization_billing', term, {})
}
</script>

<template>
    <Head title="Organization Billing" />

    <div>
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Organization Billing</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Organizations suspended for a failed retainer invoice settlement, and organizations with a
                    failed invoice that aren't suspended. Retrying a settlement never automatically lifts a
                    suspension -- review the organization's standing first.
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by organisation name" @search="searchFor" />

            <div class="space-y-4">
                <AdminTable :columns="columns" caption="Organizations with a billing problem" :empty="organizations.data.length === 0" empty-text="No organizations are billing-suspended or have a failed invoice.">
                    <AdminRow v-for="organization in organizations.data" :key="organization.id">
                        <AdminCell label="Organization"><span class="font-medium text-gray-900">{{ organization.name }}</span></AdminCell>
                        <AdminCell label="Suspended since">
                            <template v-if="organization.billingSuspendedAt">{{ formatDate(organization.billingSuspendedAt) }}</template>
                            <span v-else class="text-gray-500">not suspended</span>
                        </AdminCell>
                        <AdminCell label="Reason"><div class="md:max-w-[240px] text-gray-600">{{ organization.billingSuspensionReason ?? '--' }}</div></AdminCell>
                        <AdminCell label="Latest failed invoice">
                            <template v-if="organization.latestFailedInvoice">
                                {{ formatDate(organization.latestFailedInvoice.periodStart) }} &ndash;
                                {{ formatDate(organization.latestFailedInvoice.periodEnd) }}
                                ({{ formatMoney(organization.latestFailedInvoice.amount, organization.latestFailedInvoice.currency) }})
                            </template>
                            <span v-else class="text-gray-400">none</span>
                        </AdminCell>
                        <AdminCell label="Payment method">
                            <AdminStatusPill
                                :label="organization.hasPaymentInstrument ? 'on file' : 'missing'"
                                :tone="organization.hasPaymentInstrument ? 'green' : 'red'"
                            />
                        </AdminCell>
                        <AdminCell actions>
                            <AdminActionButton
                                v-if="organization.latestFailedInvoice"
                                tone="primary"
                                :disabled="retryForm.processing"
                                :aria-label="`Retry the failed settlement for ${organization.name}`"
                                @click="retrySettlement(organization.latestFailedInvoice.id)"
                            >Retry settlement</AdminActionButton>
                            <AdminActionButton
                                v-if="organization.billingSuspendedAt"
                                :disabled="liftForm.processing"
                                :aria-label="`Lift the billing suspension of ${organization.name}`"
                                @click="liftSuspension(organization.id)"
                            >Lift suspension</AdminActionButton>
                        </AdminCell>
                    </AdminRow>
                </AdminTable>

                <Pagination :meta="organizations.meta" @navigate="goToPage" />
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
