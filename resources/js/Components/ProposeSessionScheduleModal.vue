<script setup>
import { ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import TextBox from '@/Components/TextBox.vue';
import InputError from '@/Components/InputError.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Select from '@/Components/Select.vue';
import useErrorHandler from '@/Composables/useErrorHandler';

// SCRUM-208 (TT-2.5c): either participant (client or counsellor) may propose a session time for
// an active Therapy -- EnsureCanProposeSessionScheduleAction gates on Therapy::isParticipant(),
// not a counsellor-only check like CreateSessionFormModal's "create session".
const props = defineProps({
    show: { type: Boolean, default: false },
    therapy: { default: null },
})

const emit = defineEmits(['close', 'sent', 'alert'])
const { setErrorData, clearErrorData } = useErrorHandler()

const form = ref(defaultForm())
const errors = ref({})
const sending = ref(false)

function defaultForm() {
    return {
        startTime: '',
        endTime: '',
        name: '',
        about: '',
        type: '',
        paymentType: '',
    }
}

watch(() => props.show, (show) => {
    if (!show) return
    form.value = defaultForm()
    errors.value = {}
})

const FIELD_KEYS = ['startTime', 'endTime', 'name', 'about', 'type', 'paymentType', 'expiryDays']

function buildPayload() {
    const payload = {
        startTime: new Date(form.value.startTime).toISOString(),
        endTime: new Date(form.value.endTime).toISOString(),
        name: form.value.name,
        about: form.value.about,
    }

    // sessions.type/payment_type are NOT NULL -- always resolve to a concrete value the same way
    // CreateSessionFormModal.vue does for a direct session create, rather than omitting the field
    // whenever its selector isn't shown (the backend also defaults these, but sending a real
    // value here keeps this form's own behavior self-consistent with that one).
    payload.type = props.therapy?.allowInPerson ? (form.value.type || 'ONLINE') : 'ONLINE'
    payload.paymentType = props.therapy?.paymentType === 'PAID' ? form.value.paymentType : 'FREE'

    return payload
}

async function send() {
    sending.value = true
    clearErrorData(errors, FIELD_KEYS)

    await axios.post(route('api.session_schedule_proposals.store', { therapyId: props.therapy.id }), buildPayload())
        .then(() => {
            emit('alert', { type: 'success', message: 'Your proposed session time has been sent.' })
            emit('sent')
        })
        .catch((err) => {
            if (err.response?.data?.errors) {
                setErrorData(errors, err.response.data.errors, FIELD_KEYS)
            }
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not send the proposal.' })
        })

    sending.value = false
}
</script>

<template>
    <Modal :show="show" @close="() => emit('close')">
        <div class="p-6" v-if="therapy">
            <div class="text-lg font-bold text-gray-900 mb-4">Propose a Session Time</div>
            <FormLoader :show="sending" text="sending" />

            <form @submit.prevent="send" class="space-y-4">
                <div>
                    <InputLabel for="proposeStartTime" value="Start Time" />
                    <TextInput id="proposeStartTime" type="datetime-local" class="mt-1 block w-full" v-model="form.startTime" required />
                    <InputError class="mt-2" :message="errors.startTime" />
                </div>

                <div>
                    <InputLabel for="proposeEndTime" value="End Time" />
                    <TextInput id="proposeEndTime" type="datetime-local" class="mt-1 block w-full" v-model="form.endTime" required />
                    <InputError class="mt-2" :message="errors.endTime" />
                </div>

                <div>
                    <InputLabel for="proposeName" value="Name" />
                    <TextInput id="proposeName" type="text" class="mt-1 block w-full" v-model="form.name" required />
                    <InputError class="mt-2" :message="errors.name" />
                </div>

                <div>
                    <InputLabel for="proposeAbout" value="About" />
                    <TextBox id="proposeAbout" class="mt-1 block w-full" v-model="form.about" rows="4" required />
                    <InputError class="mt-2" :message="errors.about" />
                </div>

                <div v-if="therapy.allowInPerson">
                    <InputLabel for="proposeType" value="Type" />
                    <Select
                        id="proposeType"
                        class="mt-1 block w-full"
                        v-model="form.type"
                        :options="['ONLINE', {value: 'IN_PERSON', name: 'in person'}]"
                        :default-option="'select type'"
                    />
                    <InputError class="mt-2" :message="errors.type" />
                </div>

                <div v-if="therapy.paymentType === 'PAID'">
                    <InputLabel for="proposePaymentType" value="Payment Type" />
                    <Select
                        id="proposePaymentType"
                        class="mt-1 block w-full"
                        v-model="form.paymentType"
                        :options="['free', 'paid']"
                        :default-option="'select payment type'"
                    />
                    <InputError class="mt-2" :message="errors.paymentType" />
                </div>

                <div class="flex items-center justify-end">
                    <PrimaryButton :disabled="sending">send proposal</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
