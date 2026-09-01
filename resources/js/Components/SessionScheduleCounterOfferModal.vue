<script setup>
import { ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import FormLoader from '@/Components/FormLoader.vue';
import useErrorHandler from '@/Composables/useErrorHandler';

// A counter-offer only ever has to change the time -- everything else (name/about/type/
// paymentType) falls back to the proposal it's superseding
// (CounterOfferSessionScheduleProposalAction), so only startTime/endTime are collected here,
// mirroring CompensationCounterOfferModal.vue's structure for the analogous negotiation type.
const props = defineProps({
    request: { default: null },
})

const emit = defineEmits(['close', 'sent', 'alert'])
const { setErrorData, clearErrorData } = useErrorHandler()

const form = ref(defaultForm())
const errors = ref({})
const sending = ref(false)

function defaultForm() {
    return { startTime: '', endTime: '' }
}

watch(() => props.request, () => {
    form.value = defaultForm()
    errors.value = {}
})

const FIELD_KEYS = ['startTime', 'endTime']

async function send() {
    sending.value = true
    clearErrorData(errors, FIELD_KEYS)

    await axios.post(route('api.session_schedule_proposals.counter_offer', { requestId: props.request.id }), {
        startTime: new Date(form.value.startTime).toISOString(),
        endTime: new Date(form.value.endTime).toISOString(),
    })
        .then(() => {
            emit('alert', { type: 'success', message: 'Counter-offer sent.' })
            emit('sent')
        })
        .catch((err) => {
            if (err.response?.data?.errors) {
                setErrorData(errors, err.response.data.errors, FIELD_KEYS)
            }
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not send the counter-offer.' })
        })

    sending.value = false
}
</script>

<template>
    <Modal :show="!!request" @close="() => emit('close')">
        <div class="p-6" v-if="request">
            <div class="text-lg font-bold text-gray-900 mb-4">Counter-Offer Session Time</div>
            <FormLoader :show="sending" text="sending" />

            <form @submit.prevent="send" class="space-y-4">
                <div>
                    <InputLabel for="counterStartTime" value="Start Time" />
                    <TextInput id="counterStartTime" type="datetime-local" class="mt-1 block w-full" v-model="form.startTime" required />
                    <InputError class="mt-2" :message="errors.startTime" />
                </div>

                <div>
                    <InputLabel for="counterEndTime" value="End Time" />
                    <TextInput id="counterEndTime" type="datetime-local" class="mt-1 block w-full" v-model="form.endTime" required />
                    <InputError class="mt-2" :message="errors.endTime" />
                </div>

                <div class="flex items-center justify-end">
                    <PrimaryButton :disabled="sending">send counter-offer</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
