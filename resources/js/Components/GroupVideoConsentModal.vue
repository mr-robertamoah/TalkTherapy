<script setup>
// SCRUM-313: a guardian's video consent for their ward in a group, from their list of their ward's groups -- a
// guardian of a member can't open the group itself (the user's decision, 2026-11-13). Shows only their own wards in
// it; the server re-checks everything on each action. SCRUM-329: each consent says what it allows -- watch and listen,
// or speak too when a counsellor gives them the floor -- chosen when approving, changeable after.
import { ref, watch } from 'vue'
import axios from 'axios'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    groupTherapy: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const state = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const confirmingWithdraw = ref(null)
// Per ward: the level a new approval gives (watch and listen unless the guardian chooses otherwise).
const levelFor = ref({})

watch(() => props.show, (show) => {
    if (!show) return
    state.value = null
    confirmingWithdraw.value = null
    levelFor.value = {}
    load()
})

function load() {
    loading.value = true
    error.value = ''
    axios.get(route('group.therapies.video_consent.show', { groupTherapyId: props.groupTherapy.id }))
        .then((res) => {
            state.value = res.data.data
            // Watch and listen, until the guardian chooses otherwise.
            levelFor.value = Object.fromEntries(state.value.wards.map((ward) => [ward.id, 'WATCH']))
        })
        .catch((err) => { error.value = err.response?.data?.message || 'Could not load video consent. Please try again.' })
        .finally(() => { loading.value = false })
}

function act(request) {
    if (busy.value) return
    busy.value = true
    error.value = ''
    request
        .then((res) => { state.value = res.data.data; confirmingWithdraw.value = null })
        .catch((err) => { error.value = err.response?.data?.message || 'That did not work. Please try again.' })
        .finally(() => { busy.value = false })
}

const grant = (ward, scope) => act(axios.post(route('group.therapies.video_consent.grant', { groupTherapyId: props.groupTherapy.id }), { wardId: ward.id, scope, level: levelFor.value[ward.id] }))
const changeLevel = (consent, level) => act(axios.patch(route('group.therapies.video_consent.level', { groupTherapyId: props.groupTherapy.id, consentId: consent.id }), { level }))
const levelLabel = (consent) => consent.level === 'SPEAK' ? 'can speak when given the floor' : 'watch and listen only'
const withdraw = (consent) => act(axios.delete(route('group.therapies.video_consent.revoke', { groupTherapyId: props.groupTherapy.id, consentId: consent.id })))

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : ''
}
</script>

<template>
    <MiniModal :show="show" @close="emit('close')">
        <div class="p-4 text-gray-800">
            <div class="text-center font-bold tracking-wide">Video consent</div>
            <div class="mt-1 text-center text-sm text-gray-600">{{ groupTherapy?.name }}</div>
            <hr class="my-3">

            <div v-if="loading" class="py-6 text-center text-sm text-gray-500">loading…</div>
            <template v-else-if="state">
                <p class="mb-4 text-sm text-gray-600">
                    Under-18s need a parent or guardian's consent to join this group's video calls. You choose whether they
                    only watch and listen (microphone and camera off), or may also speak when a counsellor gives them the
                    floor; an under-18 who is anonymous in the group only ever watches and listens. Give it for the whole
                    group or for one session, change or withdraw it at any time: it applies straight away.
                </p>

                <div v-if="!state.wards.length" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                    No consent is needed here: your ward is 18 or over.
                </div>

                <div v-for="ward in state.wards" :key="ward.id" class="mb-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                    <div class="font-semibold">{{ ward.name }}</div>

                    <fieldset v-if="!ward.groupConsent" class="mt-2 text-sm">
                        <legend class="text-gray-700">When you approve, they may:</legend>
                        <label class="mr-4 inline-flex items-center gap-1">
                            <input v-model="levelFor[ward.id]" type="radio" :name="`level-${ward.id}`" value="WATCH">
                            watch and listen only
                        </label>
                        <label class="inline-flex items-center gap-1">
                            <input v-model="levelFor[ward.id]" type="radio" :name="`level-${ward.id}`" value="SPEAK">
                            also speak when given the floor
                        </label>
                    </fieldset>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div v-if="ward.groupConsent" class="text-green-800">
                            Whole group: {{ levelLabel(ward.groupConsent) }}, approved by {{ ward.groupConsent.grantedByName }} on {{ formatDate(ward.groupConsent.grantedAt) }}
                        </div>
                        <div v-else class="text-amber-800">Whole group: not given</div>
                        <PrimaryButton v-if="!ward.groupConsent" :disabled="busy" @click="grant(ward, 'group')">approve for the group</PrimaryButton>
                        <div v-else class="flex flex-wrap gap-2">
                            <SecondaryButton :disabled="busy" @click="changeLevel(ward.groupConsent, ward.groupConsent.level === 'SPEAK' ? 'WATCH' : 'SPEAK')">
                                {{ ward.groupConsent.level === 'SPEAK' ? 'watch only' : 'allow speaking' }}
                            </SecondaryButton>
                            <DangerButton v-if="confirmingWithdraw === ward.groupConsent.id" :disabled="busy" @click="withdraw(ward.groupConsent)">confirm withdraw</DangerButton>
                            <SecondaryButton v-else :disabled="busy" @click="confirmingWithdraw = ward.groupConsent.id">withdraw</SecondaryButton>
                        </div>
                    </div>

                    <div v-if="state.session" class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div v-if="ward.sessionConsent" class="text-green-800">
                            {{ state.session.isLive ? 'Current session' : `Next session (${formatDate(state.session.startTime)})` }}:
                            {{ levelLabel(ward.sessionConsent) }}, approved by {{ ward.sessionConsent.grantedByName }}
                        </div>
                        <div v-else class="text-gray-700">
                            {{ state.session.isLive ? 'Current session' : `Next session (${formatDate(state.session.startTime)})` }}:
                            {{ ward.groupConsent ? 'covered by the group consent' : 'not given' }}
                        </div>
                        <PrimaryButton v-if="!ward.sessionConsent && !ward.groupConsent" :disabled="busy" @click="grant(ward, 'session')">approve this session only</PrimaryButton>
                        <div v-else-if="ward.sessionConsent" class="flex flex-wrap gap-2">
                            <SecondaryButton :disabled="busy" @click="changeLevel(ward.sessionConsent, ward.sessionConsent.level === 'SPEAK' ? 'WATCH' : 'SPEAK')">
                                {{ ward.sessionConsent.level === 'SPEAK' ? 'watch only' : 'allow speaking' }}
                            </SecondaryButton>
                            <DangerButton v-if="confirmingWithdraw === ward.sessionConsent.id" :disabled="busy" @click="withdraw(ward.sessionConsent)">confirm withdraw</DangerButton>
                            <SecondaryButton v-else :disabled="busy" @click="confirmingWithdraw = ward.sessionConsent.id">withdraw</SecondaryButton>
                        </div>
                    </div>
                </div>
            </template>

            <div v-if="error" class="mt-2 rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700">{{ error }}</div>

            <div class="mt-4 flex justify-end">
                <SecondaryButton @click="emit('close')">close</SecondaryButton>
            </div>
        </div>
    </MiniModal>
</template>
