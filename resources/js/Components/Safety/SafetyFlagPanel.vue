<script setup>
// SCRUM-32: on a therapy or group page, for its counsellors only -- raise a safety flag for the client
// (1:1) or a member (a group: current or former, by key; never a user id). The page shows it only when
// the server says they may (`canRaiseSafetyFlag`).
import { computed, ref } from 'vue'
import axios from 'axios'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import SafetyFlagModal from '@/Components/Safety/SafetyFlagModal.vue'

const props = defineProps({
    therapyId: { type: [Number, String], required: true },
    isGroup: { type: Boolean, default: false },
    clientName: { type: String, default: '' },
})

const open = ref(false)
const members = ref(null)
const memberKey = ref('')
const loadError = ref('')

const url = computed(() => props.isGroup
    ? route('api.safety_flags.group', { groupTherapyId: props.therapyId })
    : route('api.safety_flags.therapy', { therapyId: props.therapyId }))

const chosen = computed(() => members.value?.find((member) => String(member.key) === String(memberKey.value)) ?? null)

async function start() {
    if (!props.isGroup) {
        open.value = true
        return
    }
    loadError.value = ''
    await axios.get(route('api.safety_flags.group_members', { groupTherapyId: props.therapyId }))
        .then((res) => { members.value = res.data.members })
        .catch((err) => { loadError.value = err.response?.data?.message ?? 'The members could not be loaded.' })
}
</script>

<template>
    <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" aria-labelledby="safety-flag-panel-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 id="safety-flag-panel-title" class="text-sm font-semibold text-gray-900">Worried about someone's safety?</h3>
                <p class="text-sm text-gray-600">If you think {{ isGroup ? 'a member' : (clientName || 'your client') }} may need specialist or inpatient care, raise a safety flag for the TalkTherapy team.</p>
            </div>
            <SecondaryButton v-if="!isGroup || members === null" type="button" @click="start">Raise a safety flag</SecondaryButton>
        </div>

        <div v-if="isGroup && members !== null" class="mt-3 flex flex-wrap items-end gap-2">
            <div class="min-w-[12rem] flex-1">
                <label for="safety-flag-member" class="block text-xs font-medium text-gray-700">Who is it about?</label>
                <select id="safety-flag-member" v-model="memberKey" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                    <option value="" disabled>Choose a member</option>
                    <option v-for="member in members" :key="member.key" :value="member.key">{{ member.name }}{{ member.left ? ' (left the group)' : '' }}</option>
                </select>
            </div>
            <SecondaryButton type="button" :disabled="!memberKey" @click="open = true">Continue</SecondaryButton>
            <SecondaryButton type="button" @click="members = null; memberKey = ''">Cancel</SecondaryButton>
            <p v-if="!members.length" class="w-full text-sm text-gray-600">This group has no members to flag.</p>
        </div>
        <p v-if="loadError" class="mt-2 text-sm text-red-700" role="alert">{{ loadError }}</p>

        <SafetyFlagModal
            :show="open"
            :url="url"
            :member-key="isGroup ? memberKey : null"
            :person-name="isGroup ? (chosen?.name ?? 'this member') : (clientName || 'your client')"
            @close="open = false; members = null; memberKey = ''"
        />
    </section>
</template>
