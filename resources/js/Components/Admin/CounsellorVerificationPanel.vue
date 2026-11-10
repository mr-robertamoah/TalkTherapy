<script setup>
// SCRUM-31: an administrator's view of one counsellor's verification -- where it stands, its history
// (loaded when asked for) and revoking it with a reason (sent to the counsellor). Used on the
// renewals list and in the admin counsellor view.
import { computed, ref } from 'vue'
import axios from 'axios'
import CounsellorVerificationBadge from '@/Components/CounsellorVerificationBadge.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputError from '@/Components/InputError.vue'
import { refreshAdminBadges } from '@/Composables/useAdminBadges'

const props = defineProps({
    counsellorId: { type: Number, required: true },
    verification: { type: Object, default: null },
    canRevoke: { type: Boolean, default: true },
})

const emits = defineEmits(['revoked', 'alert'])

const current = ref(props.verification)
const events = ref([])
const historyOpen = ref(false)
const historyLoading = ref(false)
const nextPage = ref(1)

const revoking = ref(false)
const reason = ref('')
const reasonError = ref('')
const saving = ref(false)

const REASON_MIN = 10
const REASON_MAX = 1000

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '--'
}

const dates = computed(() => {
    const v = current.value
    if (!v) return []

    return [
        v.verifiedSince ? `First verified ${formatDate(v.verifiedSince)}` : null,
        v.expiresAt ? `${new Date(v.expiresAt) > new Date() ? (v.state === 'revoked' ? 'Was due to end' : 'Ends') : 'Ended'} ${formatDate(v.expiresAt)}` : null,
        v.revokedAt ? `Revoked ${formatDate(v.revokedAt)}` : null,
    ].filter(Boolean)
})

async function loadHistory() {
    if (!nextPage.value || historyLoading.value) return

    historyLoading.value = true
    await axios.get(route('admin.counsellors.verification.events', { counsellorId: props.counsellorId, page: nextPage.value }))
        .then((res) => {
            events.value = [...events.value, ...res.data.data]
            nextPage.value = res.data?.links?.next ? nextPage.value + 1 : 0
        })
        .catch(() => emits('alert', { type: 'failed', message: 'The history couldn\'t be loaded. Please try again.' }))
        .finally(() => { historyLoading.value = false })
}

function toggleHistory() {
    historyOpen.value = !historyOpen.value
    if (historyOpen.value && events.value.length === 0) loadHistory()
}

async function revoke() {
    const trimmed = reason.value.trim()
    if (trimmed.length < REASON_MIN || trimmed.length > REASON_MAX) {
        reasonError.value = `Give a reason of ${REASON_MIN} to ${REASON_MAX} characters. The counsellor is sent it.`
        return
    }

    saving.value = true
    reasonError.value = ''
    await axios.post(route('admin.counsellors.verification.revoke', { counsellorId: props.counsellorId }), { reason: trimmed })
        .then((res) => {
            current.value = res.data.verification
            revoking.value = false
            reason.value = ''
            events.value = []
            nextPage.value = 1
            if (historyOpen.value) loadHistory()
            refreshAdminBadges({ force: true })
            emits('revoked', res.data.verification)
            emits('alert', { type: 'success', message: 'The verification was revoked, and the counsellor has been told why.' })
        })
        .catch((err) => {
            reasonError.value = err.response?.data?.message ?? err.response?.data?.error ?? 'Something unfortunate happened. Please try again.'
        })
        .finally(() => { saving.value = false })
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-3 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <CounsellorVerificationBadge :verification="current" />
            <span v-for="date in dates" :key="date" class="text-xs text-gray-600">{{ date }}</span>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <SecondaryButton type="button" class="min-h-[40px]" :aria-expanded="historyOpen ? 'true' : 'false'" @click="toggleHistory">
                {{ historyOpen ? 'Hide history' : 'History' }}
            </SecondaryButton>
            <DangerButton
                v-if="canRevoke && ['verified', 'lapsed'].includes(current?.state) && !revoking"
                type="button"
                class="min-h-[40px]"
                @click="() => revoking = true"
            >Revoke</DangerButton>
        </div>

        <div v-if="revoking" class="mt-3 space-y-2">
            <label :for="`revoke-reason-${counsellorId}`" class="block text-sm font-medium text-gray-800">Why are you revoking it?</label>
            <textarea
                :id="`revoke-reason-${counsellorId}`"
                v-model="reason"
                rows="3"
                :maxlength="REASON_MAX"
                class="w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                placeholder="The counsellor is sent this reason."
            ></textarea>
            <p class="text-xs text-gray-600">They keep their current clients, but can't take on new work until they are verified again.</p>
            <InputError :message="reasonError" />
            <div class="flex justify-end gap-2">
                <SecondaryButton type="button" :disabled="saving" @click="() => { revoking = false; reasonError = '' }">Cancel</SecondaryButton>
                <DangerButton type="button" :disabled="saving" @click="revoke">{{ saving ? 'Revoking…' : 'Revoke verification' }}</DangerButton>
            </div>
        </div>

        <div v-if="historyOpen" class="mt-3 border-t border-gray-100 pt-3">
            <div v-if="historyLoading && events.length === 0" class="text-xs text-gray-600">Loading history…</div>
            <div v-else-if="events.length === 0" class="text-xs text-gray-600">No history yet.</div>
            <ol v-else class="space-y-2">
                <li v-for="event in events" :key="event.id" class="text-xs text-gray-700">
                    <div class="font-medium text-gray-900">{{ event.label }}</div>
                    <div class="text-gray-600">
                        {{ formatDate(event.createdAt) }}
                        <template v-if="event.actor"> · by {{ event.actor.fullName ?? event.actor.username }}</template>
                        <template v-else> · automatic</template>
                        <template v-if="event.expiresAt"> · end date {{ formatDate(event.expiresAt) }}</template>
                        <template v-if="event.windowDays"> · {{ event.windowDays }}-day reminder</template>
                        <template v-if="event.licenseIds?.length"> · {{ event.licenseIds.length }} {{ event.licenseIds.length === 1 ? 'licence' : 'licences' }} checked</template>
                    </div>
                    <div v-if="event.reason" class="mt-0.5 whitespace-pre-line text-gray-700">"{{ event.reason }}"</div>
                </li>
            </ol>
            <div v-if="nextPage && events.length" class="mt-2">
                <SecondaryButton type="button" :disabled="historyLoading" @click="loadHistory">Older</SecondaryButton>
            </div>
        </div>
    </div>
</template>
