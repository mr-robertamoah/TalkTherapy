<script setup>
import { computed, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import { parseISO, format } from 'date-fns';

// SCRUM-208 (TT-2.5c): renders a pending session-schedule proposal's state and the actions
// available to the current viewer -- a sibling section to the existing assistance-request
// banner (TherapyInformation.vue), not folded into it, since the round/stale/counter-offer state
// here is meaningfully richer than a plain accept/reject.
const props = defineProps({
  proposal: { default: null },
  userId: { default: null },
  computedIsParticipant: { type: Boolean, default: false },
  responding: { type: Boolean, default: false },
})

const emit = defineEmits(['clicked-response', 'clicked-counter-offer'])

const rejectReason = ref('')

// Stored server-side via Carbon::toDateTimeString() in UTC (space-separated, no 'T'/offset) --
// normalize to a proper ISO string before parsing so this always renders in the viewer's local
// time, regardless of what the browser's Date constructor would otherwise guess.
function toLocalDateTime(dateTime) {
  if (!dateTime) return ''
  const iso = dateTime.includes('T') ? dateTime : `${dateTime.replace(' ', 'T')}Z`
  return format(parseISO(iso), "d MMM yyyy, h:mm a")
}

function partyIsViewer(party) {
  if (!party || !props.userId) return false
  return party.isUser ? party.id === props.userId : party.userId === props.userId
}

const isToViewer = computed(() => partyIsViewer(props.proposal?.to))
const isFromViewer = computed(() => partyIsViewer(props.proposal?.from))
const isStale = computed(() => !!props.proposal?.proposal?.staleReason)

function accept() {
  emit('clicked-response', 'accepted')
}

function reject() {
  emit('clicked-response', 'rejected')
}

function rejectWithReason() {
  if (!rejectReason.value) return
  emit('clicked-response', 'rejected', rejectReason.value)
  rejectReason.value = ''
}
</script>

<template>
  <div
    v-if="proposal && computedIsParticipant"
    class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4"
  >
    <div class="p-6 bg-white overflow-hidden shadow-sm sm:rounded-lg relative">
      <FormLoader :show="responding" text="responding" />

      <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">
        Proposed Session Time
      </div>

      <div class="text-sm text-gray-700 space-y-1 mb-4">
        <div v-if="proposal.proposal?.name" class="font-semibold">{{ proposal.proposal.name }}</div>
        <div>{{ toLocalDateTime(proposal.proposal?.startTime) }} &ndash; {{ toLocalDateTime(proposal.proposal?.endTime) }}</div>
        <div v-if="proposal.proposal?.about" class="text-gray-600">{{ proposal.proposal.about }}</div>
        <div v-if="proposal.round" class="text-xs text-gray-500">round {{ proposal.round }}</div>
      </div>

      <!-- Option C: the time is no longer valid -- reject outright, counter-propose, or reject
           with a reason asking the other party to propose again. Accept is deliberately not
           offered again here since it already failed against current data. -->
      <template v-if="isToViewer && isStale">
        <div class="text-sm text-center text-red-700 bg-red-50 rounded p-2 mb-4">
          {{ proposal.proposal.staleReason }}
        </div>

        <div class="flex flex-wrap justify-end items-center gap-2 mb-4">
          <DangerButton :disabled="responding" @click="reject" class="shrink-0">reject</DangerButton>
          <SecondaryButton :disabled="responding" @click="$emit('clicked-counter-offer')" class="shrink-0">counter-propose</SecondaryButton>
        </div>

        <div class="flex items-start gap-2">
          <TextBox
            v-model="rejectReason"
            rows="2"
            class="flex-1"
            placeholder="reject with a reason, asking them to propose a new time"
          />
          <SecondaryButton :disabled="responding || !rejectReason" @click="rejectWithReason" class="shrink-0">
            reject with reason
          </SecondaryButton>
        </div>
      </template>

      <template v-else-if="isToViewer">
        <div class="flex flex-wrap justify-end items-center gap-2">
          <PrimaryButton :disabled="responding" @click="accept" class="shrink-0">accept</PrimaryButton>
          <SecondaryButton :disabled="responding" @click="$emit('clicked-counter-offer')" class="shrink-0">counter-propose</SecondaryButton>
          <DangerButton :disabled="responding" @click="reject" class="shrink-0">reject</DangerButton>
        </div>
      </template>

      <template v-else-if="isFromViewer">
        <div class="text-sm text-center text-gray-600">
          your proposed session time is pending a response.
        </div>
      </template>
    </div>
  </div>
</template>
