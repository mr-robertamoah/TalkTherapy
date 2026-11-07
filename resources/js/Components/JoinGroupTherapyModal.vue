<script setup>
// SCRUM-114: joining a group from its card without leaving the page -- a small centred modal with
// the one choice joining needs (anonymous or by name; locked to anonymous in an anonymous group),
// then the existing join endpoint. For a group that needs approval it sends the request instead.
// SCRUM-392: the one join modal -- the group page uses it too, including for a member invitation
// link ($invitation, its uuid: joined straight away through the link). The anonymity choice starts
// from the person's own saved preference everywhere.
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import MiniModal from './MiniModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import SecondaryButton from './SecondaryButton.vue'
import InputError from './InputError.vue'
import { refusalMessage } from '@/Composables/useOfferToHelp'
import { usePage } from '@inertiajs/vue3'
import useEnums from '@/Composables/useEnums'
import useAuth from '@/Composables/useAuth'

const { RequestTypeEnum } = useEnums()
const { goToLogin } = useAuth()

const props = defineProps({
    show: { type: Boolean, default: false },
    group: { type: Object, required: true },
    needsApproval: { type: Boolean, default: false },
    invitation: { type: String, default: null },
})

const emits = defineEmits(['close', 'done'])

// An invitation is joined straight away, whatever the group's own setting.
const approval = computed(() => props.needsApproval && !props.invitation)
const anonymous = ref(false)
const sending = ref(false)
const error = ref('')

watch(() => props.show, (show) => {
    if (!show) return
    error.value = ''
    // Their own default (Preferences), unless the group makes everyone anonymous.
    anonymous.value = props.group.anonymous || !!usePage().props.auth.user?.settings?.anonymous
})

async function confirm() {
    sending.value = true
    error.value = ''
    const url = props.invitation
        ? route('api.links.join', { uuid: props.invitation })
        : route('api.group.therapies.join', { groupTherapyId: props.group.id })

    await axios.post(url, { anonymous: props.group.anonymous ? true : anonymous.value })
        .then((res) => {
            const joined = !!props.invitation || res.data.result?.type !== RequestTypeEnum.groupTherapyMembership
            // SCRUM-392: the group's member count after joining, when the answer carries it.
            emits('done', { joined, membersCount: joined ? (res.data.result?.membersCount ?? null) : null })
        })
        .catch((err) => {
            goToLogin(err)
            error.value = refusalMessage(err)
        })
        .finally(() => { sending.value = false })
}
</script>

<template>
    <MiniModal :show="show" :labelledby="`join-group-title-${group.id}`" @close="emits('close')">
        <div class="p-1 text-gray-800">
            <h2 :id="`join-group-title-${group.id}`" class="text-base font-semibold text-gray-900">{{ approval ? 'Ask to join' : 'Join' }} {{ group.name }}</h2>
            <p class="mt-2 text-sm text-gray-600">
                <template v-if="approval">Your request goes to the group's creator. You become a member once they accept it.</template>
                <template v-else-if="invitation">You were invited, so you become a member straight away.</template>
                <template v-else>You become a member straight away.</template>
            </p>

            <fieldset class="mt-4">
                <legend class="text-sm font-medium text-gray-800">How others in the group see you</legend>
                <div class="mt-2 space-y-2 text-sm">
                    <label class="flex items-start gap-2">
                        <input type="radio" :value="false" v-model="anonymous" :disabled="group.anonymous" class="mt-0.5" />
                        <span>By my name</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="radio" :value="true" v-model="anonymous" :disabled="group.anonymous" class="mt-0.5" />
                        <span>Anonymously</span>
                    </label>
                </div>
                <p v-if="group.anonymous" class="mt-2 text-xs text-gray-600">This group is anonymous, so every member is anonymous.</p>
            </fieldset>

            <InputError class="mt-3" :message="error" />

            <div class="mt-5 flex justify-end gap-2">
                <SecondaryButton type="button" :disabled="sending" @click="emits('close')">Cancel</SecondaryButton>
                <PrimaryButton type="button" :disabled="sending" @click="confirm">
                    {{ sending ? 'Sending…' : (approval ? 'Send request' : 'Join') }}
                </PrimaryButton>
            </div>
        </div>
    </MiniModal>
</template>
