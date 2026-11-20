<script setup>
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import Alert from '@/Components/Alert.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import FormLoader from '@/Components/FormLoader.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import useAlert from '@/Composables/useAlert';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { formatUtc } from '@/Support/commissionFree.js';
import { computed, reactive, ref, watch } from 'vue';
import { default as _ } from 'lodash';

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout });

// TT-7.6e/SCRUM-229: a dedicated page rather than another Admin.vue dispatch-table tab -- that
// pattern is already flagged as ad-hoc debt (documentation/implementation_plan.md), and the
// Organization admin dashboard (TT-6.5a) already set the precedent of a fresh page tree instead.

const { alertData, clearAlertData, setAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const props = defineProps({
    settings: { type: Object, required: true },
    payoutHistory: { type: Object, required: true },
    // TT-7.10b/SCRUM-468: platform-wide commission-free periods; only a super admin changes them.
    feeWaivers: { type: Array, default: () => [] },
    canManageFeeWaivers: { type: Boolean, default: false },
    feeWaiverReasonMax: { type: Number, default: 500 },
})

// ---- Platform settings ----

const feeForm = useForm({ percentage: props.settings.platformFeePercentage })
const amountsForm = useForm({
    amounts: props.settings.minimumPayoutAmounts.map((row) => ({ ...row })),
})

function saveFee() {
    feeForm.post(route('admin.settings.platform-fee.update'), {
        onSuccess: () => setSuccessAlertData({ message: 'Platform fee updated.' }),
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
    })
}

function saveMinimumAmounts() {
    amountsForm.post(route('admin.settings.minimum-payout.update'), {
        onSuccess: () => setSuccessAlertData({ message: 'Minimum payout thresholds updated.' }),
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
    })
}

// ---- Commission-free periods (TT-7.10b/SCRUM-468) ----

// Times are typed and sent as UTC (the labels say so); the server reads a value with no offset as UTC.
const waiverForm = useForm({ starts_at: '', ends_at: '', reason: '' })
// The row waiting for a second click to confirm "end now" or "delete": { id, kind }.
const confirmingWaiver = ref(null)
const waiverAction = ref(null)

function createWaiver() {
    waiverForm.post(route('admin.settings.platform-fee-waivers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            waiverForm.reset()
            setSuccessAlertData({ message: 'Commission-free period created.' })
        },
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
    })
}

function actOnWaiver(waiver, kind) {
    const name = kind === 'end' ? 'admin.settings.platform-fee-waivers.end' : 'admin.settings.platform-fee-waivers.delete'
    waiverAction.value = waiver.id

    router.post(route(name, { waiver: waiver.id }), {}, {
        preserveScroll: true,
        onSuccess: () => setSuccessAlertData({ message: kind === 'end' ? 'Commission-free period ended.' : 'Commission-free period deleted.' }),
        onError: (errors) => setFailedAlertData({ message: errors.waiver ?? errors.alert ?? 'Could not change this commission-free period.' }),
        onFinish: () => {
            waiverAction.value = null
            confirmingWaiver.value = null
        },
    })
}

function isConfirming(waiver, kind) {
    return confirmingWaiver.value?.id === waiver.id && confirmingWaiver.value?.kind === kind
}

const waiverColumns = [
    { label: 'Starts' },
    { label: 'Ends' },
    { label: 'Status' },
    { label: 'Reason' },
    { label: 'Created by' },
    { label: 'Actions' },
]

function waiverTone(status) {
    return { upcoming: 'blue', active: 'green', ended: 'gray', cancelled: 'amber' }[status] ?? 'gray'
}

// ---- Trigger payout on a counsellor's behalf ----

const counsellorSearch = ref('')
const counsellorResults = ref([])
const searchingCounsellors = ref(false)
const selectedCounsellor = ref(null)
const counsellorOverview = ref(null)
const loadingOverview = ref(false)
const triggeringPayout = ref(false)

const debouncedSearchCounsellors = _.debounce(() => searchCounsellors(), 500)

watch(counsellorSearch, () => {
    if (!counsellorSearch.value) {
        counsellorResults.value = []
        return
    }
    debouncedSearchCounsellors()
})

async function searchCounsellors() {
    searchingCounsellors.value = true

    await axios.get(route('admin.counsellors', { filterType: 'name', filterValue: counsellorSearch.value }))
        .then((res) => {
            counsellorResults.value = res.data.data
        })
        .catch(() => {
            setFailedAlertData({ message: 'Could not search counsellors right now.' })
        })
        .finally(() => {
            searchingCounsellors.value = false
        })
}

async function selectCounsellor(counsellor) {
    selectedCounsellor.value = counsellor
    counsellorResults.value = []
    counsellorSearch.value = ''
    loadingOverview.value = true

    await axios.get(route('admin.payouts.counsellor-overview', { counsellorId: counsellor.id }))
        .then((res) => {
            counsellorOverview.value = res.data
        })
        .catch(() => {
            setFailedAlertData({ message: "Could not load this counsellor's payout overview." })
            selectedCounsellor.value = null
        })
        .finally(() => {
            loadingOverview.value = false
        })
}

function formatMoney(minorUnitsAmount, currency) {
    if (minorUnitsAmount == null || !currency) return '--'

    return `${currency} ${(minorUnitsAmount / 100).toFixed(2)}`
}

const hasPayoutDestination = computed(() => !!counsellorOverview.value?.payoutAccount)
const belowMinimumPayout = computed(() => {
    const { availableAmount, minimumPayoutAmount } = counsellorOverview.value ?? {}

    return minimumPayoutAmount != null && (availableAmount ?? 0) < minimumPayoutAmount
})
const latestPayoutStatus = computed(() => counsellorOverview.value?.payoutHistory?.[0]?.status)
const canTriggerPayout = computed(() => {
    return !!counsellorOverview.value
        && !counsellorOverview.value.onHold
        && hasPayoutDestination.value
        && (counsellorOverview.value.availableAmount ?? 0) > 0
        && !belowMinimumPayout.value
        && !['PENDING', 'PROCESSING'].includes(latestPayoutStatus.value)
})
const payoutDisabledReason = computed(() => {
    if (!counsellorOverview.value) return ''
    // SCRUM-363
    if (counsellorOverview.value.onHold) return 'Payouts are on hold while this counsellor is suspended.'
    if (!hasPayoutDestination.value) return 'This counsellor has not set up a payout destination yet.'
    if (['PENDING', 'PROCESSING'].includes(latestPayoutStatus.value)) return 'A payout is already in progress for this counsellor.'
    if (belowMinimumPayout.value) {
        return `Available balance does not meet the minimum payout threshold for ${counsellorOverview.value.currency}.`
    }
    if (!(counsellorOverview.value.availableAmount ?? 0) > 0) return 'No earnings are available to withdraw for this counsellor.'

    return ''
})

const payoutForm = useForm({})

function triggerPayoutForSelectedCounsellor() {
    triggeringPayout.value = true

    payoutForm.transform((data) => ({ ...data, counsellorId: selectedCounsellor.value.id })).post(route('payout.trigger'), {
        onSuccess: () => {
            setSuccessAlertData({ message: `Payout requested for ${selectedCounsellor.value.name}.` })
            selectedCounsellor.value = null
            counsellorOverview.value = null
            reloadPayoutHistory()
        },
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
        onFinish: () => {
            triggeringPayout.value = false
        },
    })
}

// ---- Payout audit history ----

const history = reactive({ data: [...props.payoutHistory.data], meta: props.payoutHistory.meta })
const loadingHistoryPage = ref(false)

// Only the latest page or search asked for is shown (review).
let latestHistory = 0

async function goToHistoryPage(url) {
    if (!url) return
    const ticket = ++latestHistory
    loadingHistoryPage.value = true

    await axios.get(url)
        .then((res) => {
            if (ticket !== latestHistory) return
            history.data = res.data.data
            history.meta = res.data.meta
        })
        .finally(() => {
            if (ticket === latestHistory) loadingHistoryPage.value = false
        })
}

// SCRUM-390: by the counsellor's name or username, or the payout's reference.
const historySearch = ref('')

async function reloadPayoutHistory() {
    await goToHistoryPage(route('admin.payouts', historySearch.value ? { search: historySearch.value } : {}))
}

function searchHistory(term) {
    historySearch.value = term
    reloadPayoutHistory()
}

// SCRUM-430: the history as the shared admin table; a payout's status as a pill.
const historyColumns = [
    { label: 'Counsellor' },
    { label: 'Amount' },
    { label: 'Status' },
    { label: 'Initiated by' },
    { label: 'Reference' },
    { label: 'Failure reason' },
    { label: 'Date' },
]

function statusTone(status) {
    if (status === 'SUCCEEDED') return 'green'
    if (status === 'FAILED') return 'red'

    return 'gray'
}
</script>

<template>
    <Head title="Admin Payouts" />

    <div>
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">

            <!-- Platform Settings -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">Platform Settings</div>
                <div class="mt-1 text-sm text-gray-600">
                    Controls used across every counsellor's earnings/payout calculation.
                </div>

                <div class="mt-6 grid sm:grid-cols-2 gap-8">
                    <form @submit.prevent="saveFee">
                        <InputLabel for="feePercentage" value="Platform Fee (%)" />
                        <div class="flex items-center gap-2 mt-1">
                            <TextInput
                                id="feePercentage"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                class="block w-full"
                                v-model="feeForm.percentage"
                            />
                            <PrimaryButton :class="{ 'opacity-25': feeForm.processing }" :disabled="feeForm.processing">save</PrimaryButton>
                        </div>
                        <InputError class="mt-2" :message="feeForm.errors.percentage" />
                    </form>

                    <form @submit.prevent="saveMinimumAmounts">
                        <InputLabel value="Minimum Payout Amount" />
                        <div
                            v-for="(row, index) in amountsForm.amounts"
                            :key="row.currency"
                            class="flex items-center gap-2 mt-1"
                        >
                            <span class="text-sm text-gray-600 w-12">{{ row.currency }}</span>
                            <TextInput
                                type="number"
                                min="0"
                                step="0.01"
                                class="block w-full"
                                v-model="amountsForm.amounts[index].amount"
                            />
                        </div>
                        <InputError class="mt-2" :message="amountsForm.errors.amounts" />
                        <div class="flex justify-end mt-2">
                            <PrimaryButton :class="{ 'opacity-25': amountsForm.processing }" :disabled="amountsForm.processing">save</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Commission-free periods (TT-7.10b/SCRUM-468) -->
            <section aria-labelledby="fee-waivers-heading" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h2 id="fee-waivers-heading" class="text-lg font-medium text-gray-900">Commission-free periods</h2>
                <div class="mt-1 text-sm text-gray-600">
                    No platform fee is taken on payments made during a period, across the whole platform. Counsellors and
                    organisations see the dates (never the reason) while one is running or starts within 30 days.
                    <template v-if="!canManageFeeWaivers"> Only a super administrator can add, end or delete one.</template>
                </div>

                <form v-if="canManageFeeWaivers" class="mt-6 grid gap-4 sm:grid-cols-2" @submit.prevent="createWaiver">
                    <div>
                        <InputLabel for="waiverStartsAt" value="Starts (UTC/GMT)" />
                        <TextInput id="waiverStartsAt" type="datetime-local" class="mt-1 block w-full" v-model="waiverForm.starts_at" required />
                        <InputError class="mt-2" :message="waiverForm.errors.starts_at" />
                    </div>
                    <div>
                        <InputLabel for="waiverEndsAt" value="Ends (UTC/GMT)" />
                        <TextInput id="waiverEndsAt" type="datetime-local" class="mt-1 block w-full" v-model="waiverForm.ends_at" required />
                        <InputError class="mt-2" :message="waiverForm.errors.ends_at" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel for="waiverReason" value="Reason (administrators only)" />
                        <textarea
                            id="waiverReason"
                            v-model="waiverForm.reason"
                            rows="2"
                            :maxlength="feeWaiverReasonMax"
                            required
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                        ></textarea>
                        <InputError class="mt-2" :message="waiverForm.errors.reason || waiverForm.errors.counsellor_id" />
                    </div>
                    <div class="sm:col-span-2 flex items-center justify-between gap-4">
                        <p class="text-xs text-gray-500">A period runs from its start up to (not including) its end. It can't start in the past or overlap another.</p>
                        <PrimaryButton :class="{ 'opacity-25': waiverForm.processing }" :disabled="waiverForm.processing">add period</PrimaryButton>
                    </div>
                </form>

                <div class="mt-6">
                    <AdminTable :columns="waiverColumns" caption="Commission-free periods" :empty="!feeWaivers.length" empty-text="No commission-free periods yet.">
                        <AdminRow v-for="waiver in feeWaivers" :key="waiver.id">
                            <AdminCell label="Starts" half><span class="whitespace-nowrap">{{ formatUtc(waiver.startsAt) }}</span></AdminCell>
                            <AdminCell label="Ends" half><span class="whitespace-nowrap">{{ waiver.endsAt ? formatUtc(waiver.endsAt) : 'no end set' }}</span></AdminCell>
                            <AdminCell label="Status" half>
                                <AdminStatusPill :label="waiver.status" :tone="waiverTone(waiver.status)" />
                                <div v-if="waiver.endedBy" class="mt-1 text-xs text-gray-500">ended by {{ waiver.endedBy }}</div>
                            </AdminCell>
                            <AdminCell label="Reason"><span class="whitespace-pre-line break-words">{{ waiver.reason }}</span></AdminCell>
                            <AdminCell label="Created by" half>{{ waiver.createdBy ?? '--' }}</AdminCell>
                            <AdminCell actions>
                                <template v-if="waiver.canEnd">
                                    <template v-if="isConfirming(waiver, 'end')">
                                        <DangerButton :disabled="waiverAction === waiver.id" @click="actOnWaiver(waiver, 'end')">confirm end now</DangerButton>
                                        <SecondaryButton :disabled="waiverAction === waiver.id" @click="confirmingWaiver = null">cancel</SecondaryButton>
                                    </template>
                                    <SecondaryButton v-else @click="confirmingWaiver = { id: waiver.id, kind: 'end' }">end now</SecondaryButton>
                                </template>
                                <template v-if="waiver.canDelete">
                                    <template v-if="isConfirming(waiver, 'delete')">
                                        <DangerButton :disabled="waiverAction === waiver.id" @click="actOnWaiver(waiver, 'delete')">confirm delete</DangerButton>
                                        <SecondaryButton :disabled="waiverAction === waiver.id" @click="confirmingWaiver = null">cancel</SecondaryButton>
                                    </template>
                                    <SecondaryButton v-else @click="confirmingWaiver = { id: waiver.id, kind: 'delete' }">delete</SecondaryButton>
                                </template>
                            </AdminCell>
                        </AdminRow>
                    </AdminTable>
                </div>
            </section>

            <!-- Trigger Payout -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">Trigger Payout</div>
                <div class="mt-1 text-sm text-gray-600">
                    Select a counsellor to view their available balance and trigger a payout on their behalf.
                </div>

                <div class="mt-4 relative">
                    <TextInput
                        v-model="counsellorSearch"
                        placeholder="search counsellor by name/username"
                        class="w-full"
                    />
                    <div v-if="counsellorResults.length" class="border border-gray-200 rounded mt-1 max-h-60 overflow-y-auto bg-white shadow">
                        <div
                            v-for="counsellor in counsellorResults"
                            :key="counsellor.id"
                            class="p-2 text-sm cursor-pointer hover:bg-gray-100"
                            @click="() => selectCounsellor(counsellor)"
                        >{{ counsellor.name }} (@{{ counsellor.username }})</div>
                    </div>
                    <div v-if="searchingCounsellors" class="text-xs text-gray-500 mt-1">searching...</div>
                </div>

                <div class="relative mt-4" v-if="selectedCounsellor">
                    <FormLoader class="mx-auto" :show="loadingOverview" :text="'loading balance'" />

                    <div v-if="counsellorOverview" class="bg-gray-100 rounded p-4">
                        <div class="font-medium text-gray-800">{{ selectedCounsellor.name }} (@{{ selectedCounsellor.username }})</div>

                        <div class="mt-2 text-sm text-gray-600">
                            Payout destination:
                            <span v-if="hasPayoutDestination">
                                {{ counsellorOverview.payoutAccount.type === 'MOBILE_MONEY' ? 'Mobile Money' : 'Bank Account' }}
                                · {{ counsellorOverview.payoutAccount.maskedAccountNumber }}
                            </span>
                            <span v-else class="text-amber-700">none set up</span>
                        </div>

                        <div class="mt-2 text-lg font-semibold text-gray-900">
                            {{ formatMoney(counsellorOverview.availableAmount, counsellorOverview.currency) }}
                        </div>

                        <div class="flex justify-between items-center mt-4">
                            <div class="text-xs text-gray-500 max-w-[60%]" v-if="!canTriggerPayout">{{ payoutDisabledReason }}</div>
                            <div v-else></div>

                            <div class="relative">
                                <FormLoader class="mx-auto" :show="triggeringPayout" :text="'requesting payout'" />
                                <PrimaryButton
                                    :disabled="!canTriggerPayout || triggeringPayout"
                                    :class="{ 'opacity-25': !canTriggerPayout || triggeringPayout }"
                                    @click="triggerPayoutForSelectedCounsellor"
                                >trigger payout</PrimaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payout Audit History -->
            <section aria-labelledby="payout-history-heading" class="space-y-4">
                <div>
                    <h2 id="payout-history-heading" class="text-lg font-medium text-gray-900">Payout History</h2>
                    <AdminSearchBox class="mt-3" label="Search payouts by the counsellor's name or username, or the reference" @search="searchHistory" />
                    <div class="mt-1 text-sm text-gray-600">All payouts across every counsellor.</div>
                </div>

                <div class="relative space-y-4">
                    <FormLoader class="mx-auto" :show="loadingHistoryPage" :text="'loading payouts'" />

                    <AdminTable :columns="historyColumns" caption="Payout history" :empty="!history.data.length" empty-text="No payouts yet.">
                        <AdminRow v-for="payout in history.data" :key="payout.id">
                            <AdminCell label="Counsellor"><span class="font-medium text-gray-900">{{ payout.counsellorName }}</span></AdminCell>
                            <AdminCell label="Amount" half>{{ formatMoney(payout.amount, payout.currency) }}</AdminCell>
                            <AdminCell label="Status" half><AdminStatusPill :label="payout.status.toLowerCase()" :tone="statusTone(payout.status)" /></AdminCell>
                            <AdminCell label="Initiated by" half>{{ payout.initiatedBy }}</AdminCell>
                            <AdminCell label="Reference"><span class="break-all">{{ payout.reference }}</span></AdminCell>
                            <AdminCell :label="payout.failureMessage ? 'Failure reason' : ''"><span class="text-red-700">{{ payout.failureMessage }}</span></AdminCell>
                            <AdminCell label="Date" half><span class="whitespace-nowrap">{{ new Date(payout.createdAt).toLocaleDateString() }}</span></AdminCell>
                        </AdminRow>
                    </AdminTable>

                    <Pagination :meta="history.meta" @navigate="goToHistoryPage" />
                </div>
            </section>
        </div>

        <Alert
            :show="alertData.show"
            :type="alertData.type"
            :message="alertData.message"
            :time="alertData.time"
            @close="clearAlertData"
        />
    </div>
</template>
