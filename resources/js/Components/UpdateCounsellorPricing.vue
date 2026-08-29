<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import DangerButton from "./DangerButton.vue";
import Modal from "./Modal.vue";
import MiniModal from "./MiniModal.vue";
import Select from "./Select.vue";

// SCRUM-155 (TT-7.2c): informational only -- this never touches CreateTherapyRequest or the
// charge pipeline (see GetPayableAmountAction's guardrail comment). A counsellor is in exactly
// one of two modes: a single flat rate (amount + currency only), or N override rows each fully
// specifying therapyType/sessionType/per -- matching SetCounsellorPricingAction/
// EnsureCounsellorPricingDataIsValidAction's server-side rules (SCRUM-154).

const { modalData, closeModal } = useModal()
const { alertData, setAlertData, clearAlertData } = useAlert()

const emits = defineEmits(['closeModal'])
const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    counsellor: {
        default: null,
    }
})

// Sourced from config('currencies.supported') (shared via Inertia), not hardcoded -- see
// SCRUM-153's own precedent in the therapy payment forms.
const supportedCurrencies = usePage().props.supportedCurrencies ?? []
const defaultCurrency = supportedCurrencies.includes('GHS') ? 'GHS' : (supportedCurrencies[0] ?? '')
const currencyOptions = computed(() => supportedCurrencies.map(code => ({ name: code, value: code })))

const perOptions = [{ name: 'Per Therapy', value: 'PER_THERAPY' }, { name: 'Per Session', value: 'PER_SESSION' }]
const therapyTypeOptions = [{ name: 'Individual', value: 'INDIVIDUAL' }, { name: 'Group', value: 'GROUP' }]
const sessionTypeOptions = [{ name: 'Online', value: 'ONLINE' }, { name: 'In Person', value: 'IN_PERSON' }]

const mode = ref('flat') // 'flat' | 'override'
const loading = ref(false)
const flatAmount = ref('')
const flatCurrency = ref(defaultCurrency)
const overrideRows = ref([])
const confirmingClear = ref(false)

// Matches the server's `integer, min:1` rule (SetCounsellorPricingRequest) -- a bare truthiness
// check would let "0" or "-5" through client-side (any non-empty string is truthy in JS), only to
// fail silently server-side since no error currently renders for a wildcard `pricings.*.amount`
// validation message (reviewer finding).
function isValidAmount(value) {
    const number = Number(value)

    return value !== '' && Number.isInteger(number) && number > 0
}

const pricingForm = useForm({
    pricings: [],
})

function newOverrideRow() {
    return { therapyType: '', sessionType: '', per: '', amount: '', currency: defaultCurrency }
}

watch(
    () => props.show,
    () => {
        modalData.value.show = props.show

        if (props.show) setUpdateData()
    }
)

function setUpdateData() {
    pricingForm.clearErrors()
    const pricings = props.counsellor?.pricings ?? []
    const isFlat = pricings.length === 1 && !pricings[0].therapyType

    if (!pricings.length || isFlat) {
        mode.value = 'flat'
        flatAmount.value = isFlat ? pricings[0].amount : ''
        flatCurrency.value = isFlat ? pricings[0].currency : defaultCurrency
        overrideRows.value = [newOverrideRow()]
        return
    }

    mode.value = 'override'
    overrideRows.value = pricings.map((p) => ({
        therapyType: p.therapyType,
        sessionType: p.sessionType,
        per: p.per,
        amount: p.amount,
        currency: p.currency,
    }))
}

function switchMode(newMode) {
    mode.value = newMode
    pricingForm.clearErrors()
}

function addOverrideRow() {
    overrideRows.value.push(newOverrideRow())
}

function removeOverrideRow(index) {
    overrideRows.value.splice(index, 1)
}

const hasExistingPricing = computed(() => !!props.counsellor?.pricings?.length)

// Belt-and-suspenders on top of EnsureCounsellorPricingDataIsValidAction's own duplicate-scope
// rejection -- catches the mistake before submit instead of relying solely on the (now-surfaced,
// see onError below) server error.
const hasDuplicateScope = computed(() => {
    const scopes = overrideRows.value.map((row) => `${row.therapyType}|${row.sessionType}|${row.per}`)

    return new Set(scopes).size !== scopes.length
})

const canSave = computed(() => {
    if (mode.value === 'flat') return isValidAmount(flatAmount.value) && !!flatCurrency.value

    if (hasDuplicateScope.value) return false

    return overrideRows.value.length > 0 && overrideRows.value.every((row) =>
        row.therapyType && row.sessionType && row.per && isValidAmount(row.amount) && row.currency
    )
})

function closeThisModal() {
    emits('closeModal')
    closeModal()
}

function savePricing() {
    if (!canSave.value) {
        setAlertData({
            show: true,
            type: 'failed',
            message: mode.value === 'flat'
                ? 'A valid amount (a whole number of at least 1) and currency are required.'
                : hasDuplicateScope.value
                    ? 'Two override rows cannot share the same therapy type, session type, and per combination.'
                    : 'Each override row requires a therapy type, session type, per, and a valid amount (a whole number of at least 1).',
        })
        return
    }

    pricingForm.pricings = mode.value === 'flat'
        ? [{ amount: flatAmount.value, currency: flatCurrency.value }]
        : overrideRows.value.map((row) => ({ ...row }))

    pricingForm.post(route('counsellor.pricings.store', { counsellorId: props.counsellor?.id }), {
        onSuccess: () => {
            closeThisModal()
        },
        // Action-thrown rejections (e.g. a duplicate override scope slipping past the client
        // check above, or any other EnsureCounsellorPricingDataIsValidAction failure) surface via
        // Redirect::back()->withErrors(['alert' => $message]), not a field-named error -- matches
        // the onError convention already used throughout this codebase's other form modals.
        onError: (errors) => {
            if (errors.alert) {
                setAlertData({ show: true, type: 'failed', message: errors.alert })
            }
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}

function confirmClearPricing() {
    confirmingClear.value = true
}

function clearPricing() {
    pricingForm.delete(route('counsellor.pricings.destroy', { counsellorId: props.counsellor?.id }), {
        onSuccess: () => {
            confirmingClear.value = false
            closeThisModal()
        },
        onError: (errors) => {
            if (errors.alert) {
                setAlertData({ show: true, type: 'failed', message: errors.alert })
            }
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}
</script>

<template>
    <Modal
        :show="modalData.show"
        @close="closeThisModal"
    >
        <div class="p-4">
            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Pricing</div>
                <hr>
            </div>

            <div class="text-sm text-gray-600 text-center mb-4">
                This is a listed rate shown to prospective clients -- informational only, not a binding quote.
                Clients still propose their own amount when booking a therapy.
            </div>

            <FormLoader class="top-14 mx-auto" :show="loading" :text="'updating pricing'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form
                    @submit.prevent="savePricing"
                >
                    <div class="w-full mt-4 mx-auto max-w-[700px] bg-gray-200 sm:rounded-lg p-6">
                        <div class="flex justify-center gap-2 mb-6">
                            <PrimaryButton
                                type="button"
                                :class="mode == 'flat' ? '' : 'opacity-40'"
                                @click="() => switchMode('flat')"
                            >flat rate</PrimaryButton>
                            <PrimaryButton
                                type="button"
                                :class="mode == 'override' ? '' : 'opacity-40'"
                                @click="() => switchMode('override')"
                            >per service</PrimaryButton>
                        </div>

                        <template v-if="mode == 'flat'">
                            <div class="w-full mx-auto max-w-[400px]">
                                <InputLabel for="flatAmount" value="Amount" />
                                <div class="flex justify-start items-center">
                                    <Select
                                        id="flatCurrency"
                                        class="mt-1 block w-[30%] max-w-[100px]"
                                        v-model="flatCurrency"
                                        :options="currencyOptions"
                                        :default-option="'currency'"
                                    />
                                    <TextInput
                                        id="flatAmount"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full"
                                        v-model="flatAmount"
                                    />
                                </div>
                                <div class="mt-2 text-xs text-gray-500">Applies to every therapy type, session type, and payment granularity.</div>
                                <InputError class="mt-2" :message="pricingForm.errors.pricings" />
                            </div>
                        </template>

                        <template v-else>
                            <div
                                v-for="(row, index) in overrideRows"
                                :key="index"
                                class="w-full mx-auto max-w-[500px] bg-white rounded-lg p-4 mb-4"
                            >
                                <div class="flex justify-between items-center mb-2">
                                    <div class="text-sm font-medium text-gray-700">Override {{ index + 1 }}</div>
                                    <button
                                        type="button"
                                        class="text-xs text-red-600"
                                        v-if="overrideRows.length > 1"
                                        @click="() => removeOverrideRow(index)"
                                    >remove</button>
                                </div>

                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <Select
                                        v-model="row.therapyType"
                                        :options="therapyTypeOptions"
                                        :default-option="'therapy type'"
                                    />
                                    <Select
                                        v-model="row.sessionType"
                                        :options="sessionTypeOptions"
                                        :default-option="'session type'"
                                    />
                                </div>

                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <Select
                                        v-model="row.per"
                                        :options="perOptions"
                                        :default-option="'payment per?'"
                                    />
                                    <div class="flex justify-start items-center">
                                        <Select
                                            class="w-[40%] max-w-[100px]"
                                            v-model="row.currency"
                                            :options="currencyOptions"
                                            :default-option="'currency'"
                                        />
                                        <TextInput
                                            type="number"
                                            min="1"
                                            class="block w-full"
                                            v-model="row.amount"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="w-full mx-auto max-w-[500px] flex justify-end mb-4">
                                <PrimaryButton type="button" @click="addOverrideRow">add another override</PrimaryButton>
                            </div>
                            <InputError class="mt-2 text-center" :message="pricingForm.errors.pricings" />
                        </template>
                    </div>

                    <div class="w-full flex items-center justify-between mt-4">
                        <DangerButton
                            type="button"
                            v-if="hasExistingPricing"
                            :class="{ 'opacity-25': loading }"
                            :disabled="loading"
                            @click="confirmClearPricing"
                        >clear pricing</DangerButton>
                        <div v-else></div>

                        <PrimaryButton :class="{ 'opacity-25': loading }" :disabled="!canSave || loading">
                            save pricing
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </Modal>

    <MiniModal
        :show="confirmingClear"
        @close="() => confirmingClear = false"
    >
        <div class="text-gray-600 text-center font-bold tracking-wide">
            Clear Pricing
        </div>

        <hr class="my-2">

        <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
            Are you sure you want to clear your listed pricing? This cannot be undone.
        </div>

        <div class="flex items-center justify-end mt-4">
            <PrimaryButton class="ms-4" :disabled="loading" @click="() => confirmingClear = false">
                cancel
            </PrimaryButton>
            <DangerButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading" @click="clearPricing">
                clear pricing
            </DangerButton>
        </div>
    </MiniModal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
