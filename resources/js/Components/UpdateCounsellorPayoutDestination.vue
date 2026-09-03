<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Modal from "./Modal.vue";
import Select from "./Select.vue";

// TT-7.6d/SCRUM-228: mirrors UpdateCounsellorPricing.vue's structure -- a Modal wrapping a
// useForm() submit to a named route, with the standard onError/onBefore/onFinish alert/loading
// wiring already established across this codebase's other profile-edit modals.

const { modalData, closeModal } = useModal()
const { alertData, setAlertData, clearAlertData } = useAlert()

const emits = defineEmits(['closeModal'])
const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    payoutAccount: {
        default: null,
    },
})

const typeOptions = [
    { name: 'Bank Account', value: 'NUBAN' },
    { name: 'Mobile Money', value: 'MOBILE_MONEY' },
]
const supportedCurrencies = usePage().props.supportedCurrencies ?? []
const defaultCurrency = supportedCurrencies.includes('GHS') ? 'GHS' : (supportedCurrencies[0] ?? '')
const currencyOptions = computed(() => supportedCurrencies.map(code => ({ name: code, value: code })))

const destinationForm = useForm({
    type: 'NUBAN',
    accountNumber: '',
    bankCode: '',
    currency: defaultCurrency,
})

watch(
    () => props.show,
    () => {
        modalData.value.show = props.show

        if (props.show) setUpdateData()
    }
)

function setUpdateData() {
    destinationForm.clearErrors()
    destinationForm.type = props.payoutAccount?.type ?? 'NUBAN'
    destinationForm.accountNumber = ''
    destinationForm.bankCode = ''
    destinationForm.currency = props.payoutAccount?.currency ?? defaultCurrency
}

const canSave = computed(() => {
    return !!destinationForm.type
        && !!destinationForm.accountNumber
        && !!destinationForm.bankCode
        && !!destinationForm.currency
})

function closeThisModal() {
    emits('closeModal')
    closeModal()
}

function saveDestination() {
    if (!canSave.value) {
        setAlertData({
            show: true,
            type: 'failed',
            message: 'Account type, account number, bank/network code, and currency are all required.',
        })
        return
    }

    destinationForm.post(route('payout.destination.store'), {
        onSuccess: () => {
            closeThisModal()
        },
        onError: (errors) => {
            if (errors.alert) {
                setAlertData({ show: true, type: 'failed', message: errors.alert })
            }
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
                >{{ payoutAccount ? 'Update Payout Destination' : 'Set Up Payout Destination' }}</div>
                <hr>
            </div>

            <div class="text-sm text-gray-600 text-center mb-4">
                Where your withdrawn earnings are sent. Setting this up replaces any previous
                destination -- future withdrawals go to the new one.
            </div>

            <FormLoader class="top-14 mx-auto" :show="destinationForm.processing" :text="'saving payout destination'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form @submit.prevent="saveDestination">
                    <div class="w-full mt-4 mx-auto max-w-[500px] bg-gray-200 sm:rounded-lg p-6">
                        <div class="mb-4">
                            <InputLabel for="destinationType" value="Destination Type" />
                            <Select
                                id="destinationType"
                                class="mt-1 block w-full"
                                v-model="destinationForm.type"
                                :options="typeOptions"
                                :default-option="'destination type'"
                            />
                            <InputError class="mt-2" :message="destinationForm.errors.type" />
                        </div>

                        <div class="mb-4">
                            <InputLabel for="bankCode" :value="destinationForm.type === 'MOBILE_MONEY' ? 'Network Code' : 'Bank Code'" />
                            <TextInput
                                id="bankCode"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="destinationForm.bankCode"
                            />
                            <InputError class="mt-2" :message="destinationForm.errors.bankCode" />
                        </div>

                        <div class="mb-4">
                            <InputLabel for="accountNumber" :value="destinationForm.type === 'MOBILE_MONEY' ? 'Mobile Money Number' : 'Account Number'" />
                            <TextInput
                                id="accountNumber"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="destinationForm.accountNumber"
                            />
                            <InputError class="mt-2" :message="destinationForm.errors.accountNumber" />
                        </div>

                        <div class="mb-2">
                            <InputLabel for="currency" value="Currency" />
                            <Select
                                id="currency"
                                class="mt-1 block w-[40%] max-w-[120px]"
                                v-model="destinationForm.currency"
                                :options="currencyOptions"
                                :default-option="'currency'"
                            />
                            <InputError class="mt-2" :message="destinationForm.errors.currency" />
                        </div>
                    </div>

                    <div class="w-full flex items-center justify-end mt-4">
                        <PrimaryButton :class="{ 'opacity-25': destinationForm.processing }" :disabled="!canSave || destinationForm.processing">
                            save destination
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </Modal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
