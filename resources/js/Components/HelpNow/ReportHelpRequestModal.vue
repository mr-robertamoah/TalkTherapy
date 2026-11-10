<script setup>
// SCRUM-398: a counsellor reports an urgent help request (a prank, abuse) -- the one who answered it, or one
// it is ringing. The administrators review it; who asked is masked for them until they reveal it (logged).
// Reporting changes nothing for the person: the request isn't cancelled, others are still rung, and they
// aren't told.
import { ref, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    helpRequestId: { type: Number, default: null },
})

const emit = defineEmits(['close', 'reported'])

const reasons = [
    { value: 'prank', label: 'Prank, or not a real request' },
    { value: 'abuse', label: 'Abusive or threatening' },
    { value: 'other', label: 'Something else' },
]

const reason = ref('')
const details = ref('')
const sending = ref(false)
const error = ref('')
const done = ref(false)

watch(() => props.show, (show) => {
    if (!show) return
    reason.value = ''
    details.value = ''
    error.value = ''
    done.value = false
})

async function send() {
    sending.value = true
    error.value = ''
    await axios.post(route('help.requests.report', { helpRequest: props.helpRequestId }), { reason: reason.value, details: details.value || null })
        .then(() => { done.value = true; emit('reported') })
        .catch((err) => { error.value = err.response?.data?.message ?? 'The report could not be sent. Please try again.' })
        .finally(() => { sending.value = false })
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="space-y-4 p-6 text-gray-900">
            <div>
                <h2 class="text-lg font-semibold">Report this request</h2>
                <p class="mt-1 text-sm text-gray-600">
                    The administrators will review it. Reporting doesn't end the request, stop other counsellors being rung,
                    or tell the person. If you're unsure, it's safer to treat it as real.
                </p>
            </div>

            <template v-if="done">
                <p class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-900" role="status">Thank you. The administrators will review it.</p>
                <div class="flex justify-end"><PrimaryButton type="button" @click="emit('close')">Done</PrimaryButton></div>
            </template>

            <form v-else class="space-y-4" @submit.prevent="send">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium text-gray-800">What's wrong?</legend>
                    <label v-for="option in reasons" :key="option.value" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                        :class="reason === option.value ? 'border-gray-800 bg-gray-50' : 'border-gray-200'">
                        <input v-model="reason" type="radio" name="help-report-reason" :value="option.value" required />
                        <span>{{ option.label }}</span>
                    </label>
                </fieldset>
                <div>
                    <label for="help-report-details" class="block text-sm font-medium text-gray-800">Anything the administrators should know (optional)</label>
                    <textarea id="help-report-details" v-model="details" rows="3" maxlength="1000"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                </div>
                <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <SecondaryButton type="button" @click="emit('close')">Cancel</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="!reason || sending">{{ sending ? 'Sending…' : 'Send report' }}</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
