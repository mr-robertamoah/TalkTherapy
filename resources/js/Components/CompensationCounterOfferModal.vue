<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Select from '@/Components/Select.vue';
import useErrorHandler from '@/Composables/useErrorHandler';

// Sourced from config('currencies.supported') (shared via Inertia), not a free-text field --
// matches this codebase's existing precedent (UpdateCounsellorPricing.vue, GroupTherapyFormModal.vue).
const supportedCurrencies = usePage().props.supportedCurrencies ?? []
const defaultCurrency = supportedCurrencies.includes('USD') ? 'USD' : (supportedCurrencies[0] ?? '')
const currencyOptions = supportedCurrencies.map(code => ({ name: code, value: code }))

// Shared by both sides of a compensation negotiation -- Organization/Partials/RequestQueueSection.vue
// (an org admin countering a counsellor's proposal) and Organization/Partials/MyOrganizationRequestQueueSection.vue
// (a counsellor countering the org's proposal). Extracted once a second use site needed the exact
// same form (SCRUM-167) -- the endpoint and validation are symmetric for either party.
const props = defineProps({
    request: {
        default: null,
    },
})

const emit = defineEmits(['close', 'sent', 'alert'])
const { setErrorData } = useErrorHandler()

const form = ref(defaultForm())
const errors = ref({})
const sending = ref(false)

function defaultForm() {
    return { type: 'FIXED', amount: '', currency: defaultCurrency, percentage: '', basis: 'COUNSELLOR_RATE', expiryDays: '' }
}

watch(() => props.request, () => {
    form.value = defaultForm()
    errors.value = {}
})

// Only send the fields relevant to the selected type -- CreateOrganizationCounsellorCompensationRequest
// (reused by the counter-offer endpoint) cross-validates e.g. "a fixed compensation cannot carry
// a percentage or basis", so leftover defaults from a previously-selected type (or empty strings
// that the DTO layer would coerce to 0, not null) reliably tripped that check for every type.
function buildPayload() {
    const payload = { type: form.value.type }

    if (form.value.type === 'FIXED') {
        payload.amount = form.value.amount
        payload.currency = form.value.currency
    }

    if (form.value.type === 'PERCENTAGE') {
        payload.percentage = form.value.percentage
        payload.basis = form.value.basis
    }

    if (form.value.expiryDays) {
        payload.expiryDays = form.value.expiryDays
    }

    return payload
}

async function send() {
    sending.value = true
    errors.value = {}

    await axios.post(route('requests.compensation_counter_offer', { requestId: props.request.id }), buildPayload())
        .then(() => {
            emit('alert', { type: 'success', message: 'Counter-offer sent.' })
            emit('sent')
        })
        .catch((err) => {
            // Laravel's validation shape is {field: [messages]} -- setErrorData unwraps each to
            // a plain string InputError can render, rather than displaying the raw array.
            if (err.response?.data?.errors) {
                setErrorData(errors, err.response.data.errors, ['type', 'amount', 'currency', 'percentage', 'basis', 'expiryDays'])
            }
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not send the counter-offer.' })
        })

    sending.value = false
}
</script>

<template>
    <Modal :show="!!request" @close="() => emit('close')">
        <div class="p-6" v-if="request">
            <div class="text-lg font-bold text-gray-900 mb-4">Counter-Offer Compensation Terms</div>
            <FormLoader :show="sending" text="sending" />

            <form @submit.prevent="send" class="space-y-4">
                <div>
                    <InputLabel for="type" value="Type" />
                    <select id="type" v-model="form.type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="FIXED">Fixed</option>
                        <option value="PERCENTAGE">Percentage</option>
                        <option value="FREE">Free</option>
                    </select>
                    <InputError class="mt-2" :message="errors.type" />
                </div>

                <template v-if="form.type === 'FIXED'">
                    <div>
                        <InputLabel for="amount" value="Amount" />
                        <TextInput id="amount" type="number" min="1" step="1" class="mt-1 block w-full" v-model="form.amount" />
                        <InputError class="mt-2" :message="errors.amount" />
                    </div>
                    <div>
                        <InputLabel for="currency" value="Currency" />
                        <Select id="currency" class="mt-1 block w-full" v-model="form.currency" :options="currencyOptions" />
                        <InputError class="mt-2" :message="errors.currency" />
                    </div>
                </template>

                <template v-if="form.type === 'PERCENTAGE'">
                    <div>
                        <InputLabel for="percentage" value="Percentage" />
                        <TextInput id="percentage" type="number" min="1" max="100" step="1" class="mt-1 block w-full" v-model="form.percentage" />
                        <InputError class="mt-2" :message="errors.percentage" />
                    </div>
                    <div>
                        <InputLabel for="basis" value="Basis" />
                        <select id="basis" v-model="form.basis" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="COUNSELLOR_RATE">Counsellor's rate</option>
                            <option value="NEGOTIATED_RATE">Negotiated rate</option>
                        </select>
                        <InputError class="mt-2" :message="errors.basis" />
                    </div>
                </template>

                <div>
                    <InputLabel for="expiryDays" value="Expiry (days, optional)" />
                    <TextInput id="expiryDays" type="number" min="1" max="30" step="1" class="mt-1 block w-full" v-model="form.expiryDays" />
                    <InputError class="mt-2" :message="errors.expiryDays" />
                </div>

                <div class="flex items-center justify-end">
                    <PrimaryButton :disabled="sending">send counter-offer</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
