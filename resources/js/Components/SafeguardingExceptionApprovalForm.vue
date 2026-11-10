<script setup>
import { ref } from 'vue'
import PrimaryButton from './PrimaryButton.vue'
import SecondaryButton from './SecondaryButton.vue'
import SafeguardingExceptionTermsFields from './SafeguardingExceptionTermsFields.vue'

// SCRUM-381 (extracted from RequestBadge, SCRUM-384): the super administrator approving a
// counsellor's request for a safeguarding exception gives their own end date and reason, prefilled
// with what the counsellor proposed. Mounted afresh each time it opens, so it always starts from
// the request. The badge sends the response (`submit` with { reason, endsAt }).

const props = defineProps({
    // RequestResource's `safeguardingException` block, plus the request's id.
    requestId: { type: [Number, String], required: true },
    safeguardingException: { type: Object, required: true },
    responding: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'submit'])

const reason = ref(props.safeguardingException.reason ?? '')
const endsAt = ref('')
</script>

<template>
    <div class="mt-3 space-y-3 border-t border-gray-200 pt-3">
        <SafeguardingExceptionTermsFields
            v-model:reason="reason"
            v-model:ends-at="endsAt"
            :id-prefix="`approve-${requestId}`"
            :initial-ends-at="safeguardingException.proposedEndsAt ?? ''"
            :max-days="safeguardingException.safeguardingExceptionMaxDays"
            reason-label="Reason (kept in the audit list, never shown to the client)"
            end-label="Exception ends"
        />
        <div class="flex justify-end gap-2">
            <SecondaryButton :disabled="responding" @click="emit('cancel')">cancel</SecondaryButton>
            <PrimaryButton :disabled="responding || !reason.trim() || !endsAt" @click="emit('submit', { reason, endsAt })">grant exception</PrimaryButton>
        </div>
    </div>
</template>
