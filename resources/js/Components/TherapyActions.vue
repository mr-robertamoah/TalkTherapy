<template>
  <div id="therapy-actions-id" class="relative"></div>
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="p-6 bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
      <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">
        Actions
      </div>

      <div
        class="flex space-x-2 justify-start items-center w-full overflow-hidden overflow-x-auto p-2"
      >
        <!-- SCRUM-347: every participant may report, the client included (this panel only renders
             for participants). -->
        <PrimaryButton
          @click="$emit('clicked-report')"
          class="shrink-0"
          >make a report</PrimaryButton
        >
        
        <template v-if="therapy.status !== 'ENDED'">
          <PrimaryButton
            @click="$emit('clicked-create-session')"
            class="shrink-0"
            v-if="
              computedIsCounsellor &&
              !computedAccessPaused &&
              therapy.maxSessions > therapy.sessionsHeld
            "
            >create session</PrimaryButton
          >

          <PrimaryButton
            @click="$emit('clicked-propose-schedule')"
            class="shrink-0"
            v-if="
              therapyType === 'individual' &&
              computedIsParticipant &&
              !computedAccessPaused &&
              !activeSession &&
              !pendingSessionScheduleProposal &&
              therapy.maxSessions > therapy.sessionsHeld
            "
            >propose session time</PrimaryButton
          >

          <PrimaryButton
            @click="$emit('clicked-create-discussion')"
            class="shrink-0"
            v-if="computedIsCounsellor"
            >create discussion</PrimaryButton
          >
          
          <template v-if="!computedIsInSession">
            <!-- Only the group's creator may end it (EnsureCanUpdateTherapyAction); its members and
                 counsellors were shown a button the server refuses. -->
            <PrimaryButton
              @click="$emit('clicked-end-therapy')"
              v-if="
                (therapyType === 'group' ? computedIsUser : computedIsParticipant) &&
                therapy.status !== 'ENDED' &&
                therapy.sessionsHeld
              "
              class="shrink-0"
              >end {{ therapyType === 'group' ? 'group therapy' : 'therapy' }}</PrimaryButton
            >
            
            <template v-if="computedIsUser">
              <PrimaryButton v-if="!computedAccessPaused" @click="$emit('clicked-update')" class="shrink-0"
                >update {{ therapyType === 'group' ? 'group therapy' : 'therapy' }}</PrimaryButton
              >
              <DangerButton @click="$emit('clicked-delete')" class="shrink-0"
                >delete {{ therapyType === 'group' ? 'group therapy' : 'therapy' }}</DangerButton
              >
            </template>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import { computed } from 'vue'

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  computedIsUser: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
  computedIsParticipant: { type: Boolean, default: false },
  computedIsInSession: { type: Boolean, default: false },
  activeSession: { default: null },
  pendingSessionScheduleProposal: { default: null },
})

// SCRUM-288: no new sessions, proposals or settings changes while the therapy is paused for a missing
// guardian (the viewer's own, or this 1:1's client). Report, end and delete stay available.
const computedAccessPaused = computed(() => !!(props.therapy?.viewerAccessPaused || props.therapy?.clientAccessPaused))

defineEmits([
  'clicked-report',
  'clicked-create-session',
  'clicked-create-discussion',
  'clicked-end-therapy',
  'clicked-update',
  'clicked-delete',
  'clicked-propose-schedule',
])
</script>