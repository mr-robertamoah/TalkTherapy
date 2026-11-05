<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import SafeguardingExceptionTermsFields from '@/Components/SafeguardingExceptionTermsFields.vue'

// SCRUM-381: a counsellor asks the super administrator for a safeguarding exception, so a paused
// minor client (a 1:1) or group member (`member`: a key from the group's pausedMembers) can carry on
// for a while without a guardian. The administrator decides the actual end date.

const props = defineProps({
    show: { type: Boolean, default: false },
    therapyId: { type: [Number, String], required: true },
    isGroup: { type: Boolean, default: false },
    // { key, name } for a group member; null for a 1:1 client.
    member: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const reason = ref('')
const endsAt = ref('')
const sending = ref(false)
const error = ref('')
// Bumped on every open: it keys the terms fields, so each open starts from a fresh end date.
const opened = ref(0)

watch(() => props.show, (show) => {
    if (show) {
        reason.value = ''
        endsAt.value = ''
        error.value = ''
        opened.value++
    }
})

async function send() {
    sending.value = true
    error.value = ''

    const url = props.isGroup
        ? route('api.safeguarding_exception_requests.group', { groupTherapyId: props.therapyId })
        : route('api.safeguarding_exception_requests.therapy', { therapyId: props.therapyId })

    await axios.post(url, { reason: reason.value, proposedEndsAt: endsAt.value, memberKey: props.member?.key ?? null })
        .then(() => {
            emit('close')
            router.reload({ preserveScroll: true })
        })
        .catch((err) => {
            error.value = err.response?.data?.message ?? 'Something unfortunate happened. Please try again shortly.'
        })

    sending.value = false
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="p-6 space-y-4">
            <div>
                <div class="text-lg font-semibold text-gray-900">Ask for a safeguarding exception</div>
                <p class="mt-1 text-sm text-gray-600">
                    <template v-if="member">{{ member.name }}'s</template><template v-else>Your client's</template>
                    participation is paused because their account has no guardian. The super administrator can let it
                    carry on for up to 30 days while that is sorted out. Video calls still need a guardian's consent.
                </p>
            </div>

            <SafeguardingExceptionTermsFields
                :key="opened"
                v-model:reason="reason"
                v-model:ends-at="endsAt"
                id-prefix="safeguarding-request"
                reason-label="Why is it needed? (only the super administrator sees this)"
                end-label="Proposed end"
            />

            <div v-if="error" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md p-2" role="alert">{{ error }}</div>

            <div class="flex justify-end gap-2">
                <SecondaryButton :disabled="sending" @click="emit('close')">cancel</SecondaryButton>
                <PrimaryButton :disabled="sending || !reason.trim() || !endsAt" @click="send">send request</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
