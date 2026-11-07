<script setup>
// SCRUM-114: the one button a therapy or group card shows its viewer. Which button is decided on the
// server (ResolveTherapyCardStateAction -> item.viewer.action); this only renders it and runs it:
// join or ask (a small centred modal), offer to help (a confirm), otherwise a link to the page or a
// state that can't be acted on ("Request sent", "Full", "Ended"...). A refusal is shown on the card.
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import JoinGroupTherapyModal from './JoinGroupTherapyModal.vue'
import MiniModal from './MiniModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import SecondaryButton from './SecondaryButton.vue'
import { offerToHelp, refusalMessage } from '@/Composables/useOfferToHelp'

const props = defineProps({
    item: { type: Object, required: true },
    kind: { type: String, required: true }, // 'individual' | 'group'
})

const action = ref(props.item.viewer?.action ?? 'view')
const reason = ref(props.item.viewer?.reason ?? '')
const error = ref('')
const joining = ref(false)
const offering = ref(false)
const sending = ref(false)

const href = computed(() => props.kind === 'group'
    ? route('group.therapies.get', { groupTherapyId: props.item.id })
    : route('therapies.get', { therapyId: props.item.id }))

const states = {
    requestSent: 'Request sent',
    full: 'Full',
    ended: 'Ended',
    offerSent: 'Offer sent',
    invited: 'You\'re invited — see your requests',
    blocked: 'Not taking new members',
}

const primary = 'inline-flex items-center justify-center min-h-[40px] px-4 rounded-lg text-sm font-medium text-white transition-colors'
const colour = computed(() => props.kind === 'group' ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700')

function joined({ joined }) {
    joining.value = false
    action.value = joined ? 'open' : 'requestSent'
}

async function sendOffer() {
    sending.value = true
    error.value = ''
    await offerToHelp(props.kind, props.item.id)
        .then(() => {
            offering.value = false
            action.value = 'offerSent'
        })
        .catch((err) => { error.value = refusalMessage(err) })
        .finally(() => { sending.value = false })
}
</script>

<template>
    <div class="text-center space-y-2">
        <button v-if="action === 'join' || action === 'ask'" type="button" :class="[primary, colour]" @click="() => { error = ''; joining = true }">
            {{ action === 'join' ? 'Join' : 'Ask to join' }}
        </button>

        <button v-else-if="action === 'offer'" type="button" :class="[primary, colour]" @click="() => { error = ''; offering = true }">
            Offer to help
        </button>

        <Link v-else-if="action === 'open' || action === 'view'" :href="href" :class="[primary, colour]">
            {{ action === 'open' ? 'Open' : 'View' }}
        </Link>

        <div v-else class="flex flex-col items-center gap-1">
            <span class="inline-flex items-center min-h-[32px] px-3 rounded-full border border-gray-300 bg-white text-xs font-medium text-gray-700" :title="reason || ''">
                {{ states[action] ?? 'View' }}
            </span>
            <Link :href="href" class="text-xs font-medium text-gray-700 underline">View</Link>
        </div>

        <p v-if="error" class="text-xs text-red-700" role="alert">{{ error }}</p>

        <JoinGroupTherapyModal
            v-if="kind === 'group'"
            :show="joining"
            :group="item"
            :needs-approval="action === 'ask'"
            @close="joining = false"
            @done="joined"
        />

        <MiniModal :show="offering" @close="offering = false">
            <div class="p-1 text-left">
                <h2 class="text-base font-semibold text-gray-900">Offer to help with {{ item.name }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ kind === 'group' ? 'The group\'s creator' : 'The client' }} is asked to accept your offer.
                    You'll be told when they do.
                </p>
                <p v-if="error" class="mt-3 text-sm text-red-700" role="alert">{{ error }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="sending" @click="offering = false">Cancel</SecondaryButton>
                    <PrimaryButton type="button" :disabled="sending" @click="sendOffer">{{ sending ? 'Sending…' : 'Send offer' }}</PrimaryButton>
                </div>
            </div>
        </MiniModal>
    </div>
</template>
