<script setup>
// TT-7.11f/SCRUM-476: a counsellor's own recognitions on their profile, with their featuring consent: answer a pending
// one (its consent request, requests.respond), stop being featured, or opt back in (counsellor.recognitions.consent).
// Shown to the counsellor only (the rows are only sent to them). It never says why a recognition isn't shown to others.
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import RecognitionBadge from './RecognitionBadge.vue'
import { consentActionLabel, consentActions, consentDoneMessage, consentStateText } from '@/Support/recognitionConsent.js'

defineProps({
    recognitions: { type: Array, default: () => [] },
})

const emit = defineEmits(['done', 'failed'])

const busyId = ref(null)

async function act(recognition, action) {
    if (busyId.value) return
    busyId.value = recognition.id

    const request = action.kind === 'respond'
        ? axios.post(route('requests.respond', { requestId: recognition.consentRequestId }), { response: action.value })
        : axios.post(route('counsellor.recognitions.consent', { recognition: recognition.id }), { action: action.value })

    await request
        .then(() => {
            emit('done', consentDoneMessage(action))
            router.reload({ only: ['recognitions', 'recognitionBadges'], preserveScroll: true })
        })
        .catch((err) => {
            const data = err.response?.data
            emit('failed', data?.errors?.action?.[0] ?? data?.error ?? data?.message ?? 'Something went wrong. Please try again shortly.')
        })

    busyId.value = null
}
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-8">
            <div class="text-xl font-bold text-gray-900 mb-2">Counsellor of the month</div>
            <div class="w-12 h-1 bg-blue-600 mb-4"></div>
            <p class="text-sm text-gray-600 mb-6">
                Only you see this list. Whether you are featured is up to you, and you can change it at any time. A
                recognition is shown to others only while your profile is verified and in good standing.
            </p>

            <p class="sr-only" role="status" aria-live="polite">{{ busyId !== null ? 'Saving your choice...' : '' }}</p>

            <ul class="divide-y divide-gray-100" :aria-busy="busyId !== null">
                <li
                    v-for="recognition in recognitions"
                    :key="recognition.id"
                    class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="space-y-1">
                        <RecognitionBadge :month="recognition.month" />
                        <p class="text-sm text-gray-600">{{ consentStateText(recognition.consent) }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button
                            v-for="action in consentActions(recognition)"
                            :key="action.key"
                            type="button"
                            :disabled="busyId !== null"
                            :aria-label="consentActionLabel(action, recognition.month)"
                            class="rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                            :class="action.primary
                                ? 'bg-gray-800 text-white hover:bg-gray-700'
                                : 'border border-gray-300 bg-white text-gray-800 hover:bg-gray-50'"
                            @click="act(recognition, action)"
                        >{{ action.label }}</button>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
