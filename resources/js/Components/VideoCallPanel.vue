<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import VideoPaymentRequiredBanner from '@/Components/VideoPaymentRequiredBanner.vue'
import VideoConsentRequiredBanner from '@/Components/VideoConsentRequiredBanner.vue'
import useVideoSession from '@/Composables/useVideoSession'

// TT-3.1c/SCRUM-276: the actual video call surface (tiles, controls, connection-quality
// indicator) -- deliberately a separate component from TherapyComponent.vue (already 1421 lines,
// already mixing chat pagination/filter/typing-indicator concerns) rather than inlined there, so
// WebRTC tile-rendering and SDK teardown stay independently mountable/unmountable. Mounting this
// component IS the "join" action; unmounting it always leaves the call, so TherapyComponent.vue
// only ever needs to decide WHETHER to render this, never call join()/leave() itself.
const props = defineProps({
  session: { type: Object, required: true },
  // TT-3.2c/SCRUM-310: both display-only, mirroring the backend's own authorization exactly --
  // the backend re-checks on every removeParticipant() call regardless (RemoveParticipantFromVideoSessionAction).
  isCounsellor: { type: Boolean, default: false },
  isGroupTherapy: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const sessionRef = computed(() => props.session)
const {
  status, participants, isMuted, isCameraOn, connectionQuality, lastError, reconnecting,
  join, leave, removeParticipant, cancel, toggleMute, toggleCamera, attachVideo,
} = useVideoSession(sessionRef)

const videoElements = ref({})
// TT-3.2c/SCRUM-310: local-only "this specific remove request is in flight" + "it just failed"
// state -- removeParticipant() itself deliberately has no built-in error handling (see its own
// comment), so the acting counsellor needs feedback here rather than a click that silently does
// nothing on failure (e.g. the target already left, or a transient network error).
const removingParticipantId = ref(null)
const removeError = ref('')

function setVideoElement(participantId, element) {
  if (!element) {
    delete videoElements.value[participantId]
    return
  }

  videoElements.value[participantId] = element
  attachVideo(participantId, element)
}

function participantLabel(participant) {
  if (participant.isLocal) return 'You'
  return participant.name || 'Participant'
}

async function clickedLeave() {
  await leave()
  emit('close')
}

// TT-3.2c/SCRUM-310: shown only for a non-local tile, only when this viewer is a counsellor on a
// GroupTherapy session -- the backend's own RemoveParticipantFromVideoSessionAction is the real
// authorization (GroupTherapy-only, counsellor-only, self-removal blocked); this is purely display.
function canRemove(participant) {
  return props.isCounsellor && props.isGroupTherapy && !participant.isLocal && participant.userId != null
}

async function clickedRemove(participant) {
  removeError.value = ''
  removingParticipantId.value = participant.userId

  try {
    await removeParticipant(participant.userId)
  } catch (err) {
    removeError.value = err.response?.data?.message || 'Could not remove this participant. Please try again.'
  } finally {
    removingParticipantId.value = null
  }
}

async function retryJoin() {
  await join()
}

onMounted(() => {
  join()
})

// Always attempts a clean leave on unmount (e.g. navigating away from the chat page mid-call) --
// best-effort, mirrors useVideoSession.leave()'s own best-effort HTTP handling.
//
// Review finding (2026-09-11): only guarding on 'connected' left a real resource leak -- if this
// component unmounts while status is still 'connecting' (e.g. the session stops being joinable
// while a camera/mic permission prompt is still up), the in-flight join() would resolve later
// and open a live provider connection nobody is left to close. cancel() invalidates that in-flight
// join so it tears itself down once its next await resolves, instead of ever reaching 'connected'.
//
// TT-3.1d/SCRUM-277: also covers 'idle' -- handleDisconnected()'s own reconnect flow briefly sets
// status back to 'idle' before calling join() again, and cancel() is a safe no-op if called before
// any join was ever attempted at all.
onBeforeUnmount(() => {
  if (status.value === 'connected') leave()
  else if (status.value === 'connecting' || status.value === 'idle') cancel()
})
</script>

<template>
  <div class="mt-4 bg-white border border-gray-200 rounded-lg shadow-sm p-4">
    <div v-if="status === 'connecting' || (status === 'idle' && reconnecting)" class="text-center text-sm text-gray-600 py-8">
      {{ reconnecting ? 'Reconnecting…' : 'Connecting to video…' }}
    </div>

    <VideoPaymentRequiredBanner
      v-else-if="status === 'payment_required'"
      :message="lastError"
    />

    <VideoConsentRequiredBanner
      v-else-if="status === 'consent_required'"
      :message="lastError"
    />

    <div v-else-if="status === 'error'" class="text-center py-6">
      <div class="text-sm text-red-600 mb-3">{{ lastError }}</div>
      <PrimaryButton @click="retryJoin">try again</PrimaryButton>
    </div>

    <div v-else-if="status === 'ended'" class="text-center py-6">
      <div class="text-sm text-gray-600 mb-3">Video call ended.</div>
      <button
        type="button"
        class="text-sm text-gray-600 hover:underline"
        @click="emit('close')"
      >
        close
      </button>
    </div>

    <!-- TT-3.2c/SCRUM-310: deliberately its own branch, not folded into the 'ended' case above --
         a counsellor removed you specifically; this must never read like the call simply ended or
         like a connection dropped (architect's own explicit requirement). -->
    <div v-else-if="status === 'removed'" class="text-center py-6">
      <div class="text-sm font-medium text-red-700 mb-1">You were removed from this call</div>
      <div class="text-sm text-gray-600 mb-3">A counsellor removed you from this video call.</div>
      <button
        type="button"
        class="text-sm text-gray-600 hover:underline"
        @click="emit('close')"
      >
        close
      </button>
    </div>

    <div v-else-if="status === 'connected'">
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2 text-sm text-gray-600">
          <span
            class="inline-block w-2 h-2 rounded-full"
            :class="{
              'bg-green-500': connectionQuality === 'good',
              'bg-amber-500': connectionQuality === 'poor',
              'bg-gray-300': connectionQuality === 'unknown',
            }"
          />
          {{ connectionQuality === 'good' ? 'connection good' : connectionQuality === 'poor' ? 'connection poor' : 'connecting…' }}
        </div>
        <button
          type="button"
          class="text-sm text-gray-600 hover:underline"
          @click="clickedLeave"
        >
          leave call
        </button>
      </div>

      <div v-if="removeError" class="text-sm text-red-600 mb-3">{{ removeError }}</div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div
          v-for="participant in participants"
          :key="participant.id"
          class="relative bg-gray-900 rounded-lg overflow-hidden aspect-video"
        >
          <video
            :ref="(el) => setVideoElement(participant.id, el)"
            autoplay
            playsinline
            :muted="participant.isLocal"
            class="w-full h-full object-cover"
          />
          <span class="absolute bottom-2 left-2 text-xs text-white bg-gray-900 px-2 py-0.5 rounded">
            {{ participantLabel(participant) }}
          </span>
          <button
            v-if="canRemove(participant)"
            type="button"
            class="absolute top-2 right-2 text-xs text-white bg-gray-900 hover:bg-red-700 px-2 py-0.5 rounded disabled:opacity-50"
            :disabled="removingParticipantId === participant.userId"
            @click="clickedRemove(participant)"
          >
            {{ removingParticipantId === participant.userId ? 'removing…' : 'remove' }}
          </button>
        </div>
      </div>

      <div class="flex items-center justify-center gap-3 mt-4">
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-md border"
          :class="isMuted ? 'bg-red-50 border-red-200 text-red-700' : 'bg-white border-gray-200 text-gray-700'"
          @click="toggleMute"
        >
          {{ isMuted ? 'unmute' : 'mute' }}
        </button>
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-md border"
          :class="!isCameraOn ? 'bg-red-50 border-red-200 text-red-700' : 'bg-white border-gray-200 text-gray-700'"
          @click="toggleCamera"
        >
          {{ isCameraOn ? 'turn camera off' : 'turn camera on' }}
        </button>
      </div>
    </div>
  </div>
</template>
