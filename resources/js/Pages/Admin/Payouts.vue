<script setup>
import Alert from '@/Components/Alert.vue';
import FormLoader from '@/Components/FormLoader.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import useAlert from '@/Composables/useAlert';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { default as _ } from 'lodash';

// TT-7.6e/SCRUM-229: a dedicated page rather than another Admin.vue dispatch-table tab -- that
// pattern is already flagged as ad-hoc debt (documentation/implementation_plan.md), and the
// Organization admin dashboard (TT-6.5a) already set the precedent of a fresh page tree instead.

const { alertData, clearAlertData, setAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const props = defineProps({
    settings: { type: Object, required: true },
    payoutHistory: { type: Object, required: true },
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
        && hasPayoutDestination.value
        && (counsellorOverview.value.availableAmount ?? 0) > 0
        && !belowMinimumPayout.value
        && !['PENDING', 'PROCESSING'].includes(latestPayoutStatus.value)
})
const payoutDisabledReason = computed(() => {
    if (!counsellorOverview.value) return ''
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

async function goToHistoryPage(url) {
    if (!url) return
    loadingHistoryPage.value = true

    await axios.get(url)
        .then((res) => {
            history.data = res.data.data
            history.meta = res.data.meta
        })
        .finally(() => {
            loadingHistoryPage.value = false
        })
}

async function reloadPayoutHistory() {
    await goToHistoryPage(route('admin.payouts'))
}

function statusClass(status) {
    return {
        'text-green-700': status === 'SUCCEEDED',
        'text-red-700': status === 'FAILED',
        'text-gray-600': !['SUCCEEDED', 'FAILED'].includes(status),
    }
}
</script>

<template>
    <Head title="Admin Payouts" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] mx-auto space-y-8">

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
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">Payout History</div>
                <div class="mt-1 text-sm text-gray-600">All payouts across every counsellor.</div>

                <div class="relative mt-4">
                    <FormLoader class="mx-auto" :show="loadingHistoryPage" :text="'loading payouts'" />

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="text-gray-500 border-b border-gray-200">
                                    <th class="py-2 pr-4">Counsellor</th>
                                    <th class="py-2 pr-4">Amount</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Initiated By</th>
                                    <th class="py-2 pr-4">Reference</th>
                                    <th class="py-2 pr-4">Failure Reason</th>
                                    <th class="py-2 pr-4">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="payout in history.data" :key="payout.id" class="border-b border-gray-100">
                                    <td class="py-2 pr-4">{{ payout.counsellorName }}</td>
                                    <td class="py-2 pr-4">{{ formatMoney(payout.amount, payout.currency) }}</td>
                                    <td class="py-2 pr-4 font-medium" :class="statusClass(payout.status)">{{ payout.status.toLowerCase() }}</td>
                                    <td class="py-2 pr-4">{{ payout.initiatedBy }}</td>
                                    <td class="py-2 pr-4">{{ payout.reference }}</td>
                                    <td class="py-2 pr-4 text-red-700">{{ payout.failureMessage }}</td>
                                    <td class="py-2 pr-4">{{ new Date(payout.createdAt).toLocaleDateString() }}</td>
                                </tr>
                                <tr v-if="!history.data.length">
                                    <td colspan="7" class="py-4 text-center text-gray-500">No payouts yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Pagination :meta="history.meta" @navigate="goToHistoryPage" />
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
    </AuthenticatedLayout>
</template>
