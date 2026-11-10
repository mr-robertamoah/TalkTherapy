<script setup>
import { ref } from 'vue'
import DangerButton from './DangerButton.vue'
import SecondaryButton from './SecondaryButton.vue'
import TextBox from './TextBox.vue'
import InputLabel from './InputLabel.vue'
import InputError from './InputError.vue'

// SCRUM-387 (extracted from Admin/RefundRequests.vue): declining a refund request needs a reason
// for the client (RespondToRefundRequestAction refuses one without). Used on the refund requests
// page and in RequestBadge (the admin queue). Mounted afresh each time it opens; the parent sends
// the response (`submit` with the reason).

const props = defineProps({
    // For the field's id, unique on the page.
    requestId: { type: [Number, String], required: true },
    responding: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'submit'])

const MIN_LENGTH = 10

const reason = ref('')
const error = ref('')

function submit() {
    error.value = ''

    if (reason.value.trim().length < MIN_LENGTH) {
        error.value = `Please provide at least ${MIN_LENGTH} characters explaining why this request is being declined.`
        return
    }

    emit('submit', reason.value.trim())
}
</script>

<template>
    <div>
        <InputLabel :for="`reject_reason_${requestId}`" value="Why is this being declined?" />
        <TextBox :id="`reject_reason_${requestId}`" v-model="reason" class="mt-1 block w-full" rows="2" />
        <InputError :message="error" class="mt-1" />
        <div class="mt-2 flex gap-2">
            <DangerButton :disabled="responding" @click="submit">confirm reject</DangerButton>
            <SecondaryButton :disabled="responding" @click="emit('cancel')">cancel</SecondaryButton>
        </div>
    </div>
</template>
