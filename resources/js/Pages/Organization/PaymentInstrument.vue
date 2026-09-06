<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Alert from '@/Components/Alert.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Select from '@/Components/Select.vue';
import useAlert from '@/Composables/useAlert';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

// TT-7.3b-i/SCRUM-240: org-admin "add/replace payment method" screen -- the TT-7.3b-a backend
// (organization_payment_instruments, InitiateOrganizationPaymentInstrumentRegistrationAction) had
// no controller/route/UI of its own until this ticket. The registration flow is a real Paystack
// hosted-checkout redirect (there's no free "just verify this card" call), so this mirrors
// usePayment.js's initiate()/redirect shape, not UpdateCounsellorPayoutDestination.vue's
// synchronous post-and-redirect-back one -- that flow (bank/momo) never leaves this app.

const props = defineProps({
    organization: {
        type: Object,
        required: true,
    },
    paymentInstrument: {
        type: Object,
        default: null,
    },
    verificationAmounts: {
        type: Array,
        required: true,
    },
})

const { alertData, clearAlertData, setAlertData } = useAlert()

const supportedCurrencies = usePage().props.supportedCurrencies ?? []
const defaultCurrency = props.paymentInstrument?.currency
    ?? (supportedCurrencies.includes('GHS') ? 'GHS' : (supportedCurrencies[0] ?? ''))
const currencyOptions = computed(() => supportedCurrencies.map(code => ({ name: code, value: code })))

const currency = ref(defaultCurrency)
const currencyError = ref('')
const initiating = ref(false)

// Read once at mount, same as usePayment.js's own transactionStatus computed -- Inertia only
// flashes this on the single request right after Paystack's redirect lands the admin back here.
const transactionStatus = usePage().props.transactionStatus ?? null
const statusDismissed = ref(false)

const STATUS_MESSAGES = {
    SUCCESS: 'Payment method verified and saved.',
    FAILED: 'The verification charge failed. Please try again.',
    ABANDONED: 'We have not yet confirmed the verification charge. If you completed checkout, this may update shortly -- otherwise, feel free to try again.',
    PENDING: 'We have not yet confirmed the verification charge. If you completed checkout, this may update shortly -- otherwise, feel free to try again.',
}

const showStatusBanner = computed(() => !!transactionStatus && !statusDismissed.value)
const statusBannerType = computed(() => transactionStatus === 'SUCCESS' ? 'success' : 'failed')
const statusBannerMessage = computed(() => STATUS_MESSAGES[transactionStatus] ?? '')

const verificationAmountForSelectedCurrency = computed(() => {
    return props.verificationAmounts.find(entry => entry.currency === currency.value)?.amount ?? null
})

function formatMoney(minorUnitsAmount, currencyCode) {
    if (minorUnitsAmount == null || !currencyCode) return '--'

    return `${currencyCode} ${(minorUnitsAmount / 100).toFixed(2)}`
}

async function registerPaymentInstrument() {
    if (!currency.value) {
        currencyError.value = 'Please choose a currency.'
        return
    }
    currencyError.value = ''
    initiating.value = true

    try {
        const { data } = await axios.post(
            route('organizations.payment_instrument.initiate', { organizationId: props.organization.id }),
            { currency: currency.value }
        )
        window.location.href = data.authorizationUrl
    } catch (err) {
        initiating.value = false
        setAlertData({
            show: true,
            type: 'failed',
            message: err.response?.data?.message || 'Something unfortunate happened while starting payment-method verification. Please try again later.',
        })
    }
}
</script>

<template>
    <Head title="Payment Method" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto space-y-8">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-2xl font-bold text-gray-900">{{ organization.name }} &mdash; Payment Method</div>
                    <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                </div>
                <Link :href="route('organizations.dashboard', { organizationId: organization.id })" class="text-sm text-blue-600 hover:underline">back to dashboard</Link>
            </div>

            <div
                v-if="showStatusBanner"
                class="rounded-lg p-4 border flex items-start justify-between"
                :class="statusBannerType === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'"
            >
                <span>{{ statusBannerMessage }}</span>
                <button type="button" class="text-xs underline ml-4" @click="statusDismissed = true">dismiss</button>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">Current Payment Method</div>

                <div v-if="paymentInstrument" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-gray-700">
                    <div><span class="font-semibold">Card:</span> {{ paymentInstrument.maskedCardNumber }}</div>
                    <div v-if="paymentInstrument.cardType"><span class="font-semibold">Type:</span> {{ paymentInstrument.cardType }}</div>
                    <div v-if="paymentInstrument.bank"><span class="font-semibold">Bank:</span> {{ paymentInstrument.bank }}</div>
                    <div v-if="paymentInstrument.expMonth && paymentInstrument.expYear">
                        <span class="font-semibold">Expires:</span> {{ paymentInstrument.expMonth }}/{{ paymentInstrument.expYear }}
                    </div>
                    <div><span class="font-semibold">Currency:</span> {{ paymentInstrument.currency }}</div>
                    <div v-if="paymentInstrument.pendingCreditAmount">
                        <span class="font-semibold">Pending credit:</span> {{ formatMoney(paymentInstrument.pendingCreditAmount, paymentInstrument.currency) }}
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-gray-500 italic">No payment method on file yet.</p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="text-lg font-medium text-gray-900">{{ paymentInstrument ? 'Replace Payment Method' : 'Add Payment Method' }}</div>
                <div class="mt-1 text-sm text-gray-600">
                    You'll be redirected to a secure Paystack checkout page to enter your card details. A small,
                    nominal verification charge is made (there's no free way to just verify a card) and credited
                    toward this organization's future billing.
                    <span v-if="paymentInstrument" class="block mt-1 font-medium">This replaces the payment method above.</span>
                </div>

                <form @submit.prevent="registerPaymentInstrument" class="mt-4">
                    <div class="mb-4 max-w-[220px]">
                        <InputLabel for="currency" value="Currency" />
                        <Select
                            id="currency"
                            class="mt-1 block w-full"
                            v-model="currency"
                            :options="currencyOptions"
                            :default-option="'currency'"
                        />
                        <InputError class="mt-2" :message="currencyError" />
                        <p v-if="verificationAmountForSelectedCurrency != null" class="mt-2 text-xs text-gray-500">
                            Verification charge: {{ formatMoney(verificationAmountForSelectedCurrency, currency) }}
                        </p>
                    </div>

                    <PrimaryButton :class="{ 'opacity-25': initiating }" :disabled="initiating">
                        {{ initiating ? 'redirecting to checkout...' : (paymentInstrument ? 'replace payment method' : 'add payment method') }}
                    </PrimaryButton>
                </form>
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
