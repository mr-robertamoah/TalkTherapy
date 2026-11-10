<script setup>
// SCRUM-32: a counsellor raises a safety flag -- they think the person may need specialist or inpatient
// care. Step 1: how urgent. If imminent, the emergency number comes first (it never blocks the flag).
// Step 2: the reason and what has happened so far. The administrators are told (never who or why);
// the person isn't told here, and nothing else about the therapy changes.
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import EmergencyNumbersPanel from '@/Components/HelpNow/EmergencyNumbersPanel.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    // Where to send it (a route URL) and who it's about, as the counsellor sees them.
    url: { type: String, required: true },
    personName: { type: String, default: 'this person' },
    memberKey: { type: [String, Number], default: null },
})

const emit = defineEmits(['close', 'raised'])

const urgencies = [
    { value: 'IMMINENT', label: 'Imminent', help: 'They may be at risk of harm now or within hours.' },
    { value: 'URGENT', label: 'Urgent', help: 'They need specialist or inpatient care within days.' },
    { value: 'NON_URGENT', label: 'Not urgent', help: 'They should be referred, but there is no immediate risk.' },
]
const discussedOptions = [
    { value: 'YES', label: 'Yes' },
    { value: 'NO', label: 'No' },
    { value: 'NOT_POSSIBLE', label: 'It wasn\'t possible' },
]

const step = ref('urgency')
const urgency = ref('')
const reason = ref('')
const discussed = ref('')
const contacted = ref(null)
const contactedNote = ref('')
const guardianReason = ref('')
const sending = ref(false)
const error = ref('')
const fieldErrors = ref({})
const done = ref(false)

watch(() => props.show, (show) => {
    if (!show) return
    step.value = 'urgency'
    urgency.value = ''
    reason.value = ''
    discussed.value = ''
    contacted.value = null
    contactedNote.value = ''
    guardianReason.value = ''
    error.value = ''
    fieldErrors.value = {}
    done.value = false
})

const canSend = computed(() => reason.value.trim().length >= 10 && discussed.value && contacted.value !== null && !sending.value)

function next() {
    step.value = urgency.value === 'IMMINENT' ? 'imminent' : 'details'
}

async function send() {
    sending.value = true
    error.value = ''
    fieldErrors.value = {}
    await axios.post(props.url, {
        urgency: urgency.value,
        reason: reason.value,
        discussedWithClient: discussed.value,
        emergencyServicesContacted: contacted.value,
        emergencyServicesNote: contacted.value ? contactedNote.value : null,
        memberKey: props.memberKey,
        guardianWithholdReason: guardianReason.value || null,
    })
        .then(() => { done.value = true; emit('raised') })
        .catch((err) => {
            fieldErrors.value = err.response?.status === 422 ? (err.response.data?.errors ?? {}) : {}
            error.value = err.response?.data?.message ?? 'The flag could not be raised. Please try again, or contact the TalkTherapy team directly.'
        })
        .finally(() => { sending.value = false })
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="space-y-4 p-6 text-gray-900">
            <div>
                <h2 class="text-lg font-semibold">Raise a safety flag</h2>
                <p class="mt-1 text-sm text-gray-600">
                    For when you think {{ personName }} may need more care than therapy here can give -- specialist or inpatient care.
                    The TalkTherapy administrators are alerted; only the one handling it sees who it's about and why.
                    Raising it doesn't tell them, and nothing else about the therapy changes.
                </p>
            </div>

            <template v-if="done">
                <p class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-900" role="status">
                    The flag has been raised and the administrators alerted. If someone is in danger, don't wait for them: call the emergency number.
                </p>
                <div class="flex justify-end"><PrimaryButton type="button" @click="emit('close')">Done</PrimaryButton></div>
            </template>

            <!-- Step 1: how urgent -->
            <fieldset v-else-if="step === 'urgency'" class="space-y-3">
                <legend class="text-sm font-medium text-gray-800">How urgent is it?</legend>
                <label v-for="option in urgencies" :key="option.value" class="flex cursor-pointer gap-3 rounded-lg border p-3"
                    :class="urgency === option.value ? 'border-gray-800 bg-gray-50' : 'border-gray-200'">
                    <input v-model="urgency" type="radio" name="safety-flag-urgency" :value="option.value" class="mt-1" />
                    <span>
                        <span class="block text-sm font-semibold">{{ option.label }}</span>
                        <span class="block text-sm text-gray-600">{{ option.help }}</span>
                    </span>
                </label>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="emit('close')">Cancel</SecondaryButton>
                    <PrimaryButton type="button" :disabled="!urgency" @click="next">Next</PrimaryButton>
                </div>
            </fieldset>

            <!-- Imminent: the emergency number first -- it never stops the flag. -->
            <div v-else-if="step === 'imminent'" class="space-y-3">
                <p class="text-sm font-medium text-gray-800">If they may be harmed now, contact emergency services first. The TalkTherapy team can't send help.</p>
                <EmergencyNumbersPanel heading="Emergency services" input-id="safety-flag-country" country-label="Their country" />
                <div class="flex flex-wrap justify-end gap-2">
                    <SecondaryButton type="button" @click="step = 'urgency'">Back</SecondaryButton>
                    <PrimaryButton type="button" @click="step = 'details'">Continue to the flag</PrimaryButton>
                </div>
            </div>

            <!-- Step 2: why, and what has happened -->
            <form v-else class="space-y-4" @submit.prevent="send">
                <div>
                    <label for="safety-flag-reason" class="block text-sm font-medium text-gray-800">Why do you think so? (only the administrator handling it sees this)</label>
                    <textarea id="safety-flag-reason" v-model="reason" rows="4" maxlength="2000" required
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        :aria-invalid="!!fieldErrors.reason" aria-describedby="safety-flag-reason-help"></textarea>
                    <p id="safety-flag-reason-help" class="mt-1 text-xs text-gray-600">{{ reason.length }}/2000. At least a sentence (10 characters or more).</p>
                    <p v-if="fieldErrors.reason" class="mt-1 text-sm text-red-700">{{ fieldErrors.reason[0] }}</p>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-gray-800">Have you discussed this with them?</legend>
                    <div class="mt-1 flex flex-wrap gap-4">
                        <label v-for="option in discussedOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                            <input v-model="discussed" type="radio" name="safety-flag-discussed" :value="option.value" /> {{ option.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-sm font-medium text-gray-800">Have emergency services been contacted?</legend>
                    <div class="mt-1 flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm"><input v-model="contacted" type="radio" name="safety-flag-contacted" :value="true" /> Yes</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="contacted" type="radio" name="safety-flag-contacted" :value="false" /> No</label>
                    </div>
                    <div v-if="contacted" class="mt-2">
                        <label for="safety-flag-contacted-note" class="block text-xs font-medium text-gray-700">Who, and when (optional)</label>
                        <input id="safety-flag-contacted-note" v-model="contactedNote" type="text" maxlength="500" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                    </div>
                </fieldset>

                <!-- TT-4.3c: for a minor, on an imminent or urgent flag, their guardians are told to check in with them
                     unless there is a safeguarding reason not to (the administrator decides). -->
                <div v-if="urgency !== 'NON_URGENT'">
                    <label for="safety-flag-guardian-reason" class="block text-sm font-medium text-gray-800">If they are under 18: a safeguarding reason not to tell their guardians (optional)</label>
                    <textarea id="safety-flag-guardian-reason" v-model="guardianReason" rows="2" maxlength="1000"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    <p class="mt-1 text-xs text-gray-600">Otherwise their guardians are asked to check in with them (never told why). The administrator handling it decides. Ignored for adults.</p>
                </div>

                <p class="text-xs text-gray-600">Urgency: <strong>{{ urgencies.find((option) => option.value === urgency)?.label }}</strong> · <button type="button" class="underline" @click="step = 'urgency'">change</button></p>

                <div v-if="error" class="rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700" role="alert">{{ error }}</div>

                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="sending" @click="emit('close')">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="!canSend">{{ sending ? 'Raising…' : 'Raise the flag' }}</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
