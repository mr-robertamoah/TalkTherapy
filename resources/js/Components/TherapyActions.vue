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
        <PrimaryButton
          @click="$emit('clicked-report')"
          class="shrink-0"
          v-if="!computedIsUser"
          >make a report</PrimaryButton
        >
        
        <template v-if="therapy.status !== 'ENDED'">
          <PrimaryButton
            @click="$emit('clicked-create-session')"
            class="shrink-0"
            v-if="
              computedIsCounsellor &&
              therapy.maxSessions > therapy.sessionsHeld
            "
            >create session</PrimaryButton
          >
          
          <PrimaryButton
            @click="$emit('clicked-create-discussion')"
            class="shrink-0"
            v-if="computedIsCounsellor"
            >create discussion</PrimaryButton
          >
          
          <template v-if="!computedIsInSession">
            <PrimaryButton
              @click="$emit('clicked-end-therapy')"
              v-if="
                computedIsParticipant &&
                therapy.status !== 'ENDED' &&
                therapy.sessionsHeld
              "
              class="shrink-0"
              >end {{ therapyType === 'group' ? 'group therapy' : 'therapy' }}</PrimaryButton
            >
            
            <template v-if="computedIsUser">
              <PrimaryButton @click="$emit('clicked-update')" class="shrink-0"
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

defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  computedIsUser: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
  computedIsParticipant: { type: Boolean, default: false },
  computedIsInSession: { type: Boolean, default: false },
})

defineEmits([
  'clicked-report',
  'clicked-create-session',
  'clicked-create-discussion',
  'clicked-end-therapy',
  'clicked-update',
  'clicked-delete',
])
</script>