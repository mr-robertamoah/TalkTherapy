<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import VideoPaymentRequiredBanner from '@/Components/VideoPaymentRequiredBanner.vue'
import VideoConsentRequiredBanner from '@/Components/VideoConsentRequiredBanner.vue'
import VideoTile from '@/Components/VideoTile.vue'
import useVideoSession from '@/Composables/useVideoSession'

// TT-3.1c/SCRUM-276: the actual video call surface (tiles, controls, connection-quality
// indicator) -- deliberately a separate component from TherapyComponent.vue (already 1421 lines,
// already mixing chat pagination/filter/typing-indicator concerns) rather than inlined there, so
// WebRTC tile-rendering and SDK teardown stay independently mountable/unmountable. Mounting this
// component IS the "join" action; unmounting it always leaves the call, so TherapyComponent.vue
// only ever needs to decide WHETHER to render this, never call join()/leave() itself.
const props = defineProps({
  session: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const sessionRef = computed(() => props.session)
const {
  status, participants, isMuted, isCameraOn, connectionQuality, lastError, reconnecting,
  capabilities, holdsSpeakingGrant, handRaised, raisedHandUserIds, speakerUserIds, speakingNotice,
  idleSecondsRemaining, mediaBusy, mediaError, join, leave, removeParticipant, cancel, toggleMute, toggleCamera, attachVideo,
  raiseHand, lowerHand, grantSpeaking, revokeSpeaking,
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
// TT-3.2f-j/SCRUM-327: read from the join response's canModerate (the same group-counsellor check
// the backend makes), the one source the speaking queue below uses too.
function canRemove(participant) {
  return capabilities.value.canModerate && !participant.isLocal && participant.userId != null
}

// TT-3.2f-j/SCRUM-327 (user request): spotlight one participant large, everyone else in a narrow,
// vertically scrollable strip beside them. Local-only view state -- nobody else's layout changes.
// Falls back to the grid on its own when the focused participant leaves (their tile is gone).
const focusedParticipantId = ref(null)
const focusedParticipant = computed(() => participants.value.find((p) => p.id === focusedParticipantId.value) ?? null)
const otherParticipants = computed(() => participants.value.filter((p) => p.id !== focusedParticipantId.value))
const canFocus = computed(() => participants.value.length > 1)

// Cleared as soon as the focused tile goes, rather than only falling back while it's missing -- on
// Chime a returning attendee can reuse the same id, which would otherwise bring the spotlight back
// unexpectedly, possibly much later.
watch(participants, (list) => {
  if (focusedParticipantId.value != null && !list.some((p) => p.id === focusedParticipantId.value)) {
    focusedParticipantId.value = null
  }
})

function toggleFocus(participant) {
  focusedParticipantId.value = focusedParticipantId.value === participant.id ? null : participant.id
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

// TT-3.2f-j/SCRUM-327: speaking permission in a GroupTherapy call. Everything here is display-only
// -- the provider token enforces who can send, and the backend re-authorizes every action.
const ANONYMOUS_AUDIO_ONLY_NOTE = 'Audio only: your camera stays off because you are anonymous in this group.'
// Warn in the last minute (or the last half, for a short configured timeout), only while the
// member isn't sending -- sending keeps the grant alive on its own.
const showIdleWarning = computed(() => {
  if (!holdsSpeakingGrant.value || idleSecondsRemaining.value == null) return false
  if (!isMuted.value || isCameraOn.value) return false
  return idleSecondsRemaining.value <= Math.min(60, capabilities.value.speakingGrantIdleTimeout / 2)
})

const handBusy = ref(false)
const handError = ref('')

async function clickedHand() {
  handError.value = ''
  handBusy.value = true

  try {
    if (handRaised.value) await lowerHand()
    else await raiseHand()
  } catch (err) {
    handError.value = err.response?.data?.message || 'Could not update your raised hand. Please try again.'
  } finally {
    handBusy.value = false
  }
}

// One-time reveal warning: a granted NON-anonymous member is shown, once per call, that unmuting
// or turning the camera on lets everyone hear/see them under their real name -- before the first
// time either actually happens. An anonymous member is audio-only under a masked name, so the
// warning doesn't apply to them.
const revealAcknowledged = ref(false)
const pendingRevealAction = ref(null) // null | 'mute' | 'camera'

function needsRevealWarning() {
  return holdsSpeakingGrant.value && !capabilities.value.isAnonymous && !revealAcknowledged.value
}

function clickedMute() {
  if (isMuted.value && needsRevealWarning()) {
    pendingRevealAction.value = 'mute'
    return
  }
  toggleMute()
}

function clickedCamera() {
  if (!isCameraOn.value && needsRevealWarning()) {
    pendingRevealAction.value = 'camera'
    return
  }
  toggleCamera()
}

// A revoke (or idle expiry) while the warning is open makes its action moot -- close it rather than
// let "unmute" switch on a mic the provider is already refusing.
watch(holdsSpeakingGrant, (granted) => {
  if (!granted) pendingRevealAction.value = null
})

function confirmReveal() {
  const action = pendingRevealAction.value
  pendingRevealAction.value = null
  if (!holdsSpeakingGrant.value) return

  revealAcknowledged.value = true

  if (action === 'mute') toggleMute()
  else if (action === 'camera') toggleCamera()
}

// Counsellor's queue. Names come from the call's own tiles (already anonymity-masked server-side);
// a hand or grant always belongs to someone in the call, so a missing tile is only ever momentary.
const moderatingUserId = ref(null)
const moderationError = ref('')

// A broadcast changing the lists means some counsellor's action did go through (possibly a retry
// of the one that failed here, or another counsellor's), so a stale error would only mislead.
watch([raisedHandUserIds, speakerUserIds], () => {
  moderationError.value = ''
})

function labelForUserId(userId) {
  const participant = participants.value.find((p) => p.userId === userId)
  return participant ? participantLabel(participant) : 'Participant'
}

async function clickedModerate(userId, action) {
  moderationError.value = ''
  moderatingUserId.value = userId

  try {
    if (action === 'grant') await grantSpeaking(userId)
    else await revokeSpeaking(userId)
  } catch (err) {
    moderationError.value = err.response?.data?.message || 'Could not change who can speak. Please try again.'
  } finally {
    moderatingUserId.value = null
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

      <!-- TT-3.2f-j/SCRUM-327: a receive-only member's own speaking state. -->
      <div
        v-if="capabilities.receiveOnly"
        class="mb-3 rounded-md border p-3 text-sm"
        :class="holdsSpeakingGrant ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50'"
        data-testid="video-speaking-status"
      >
        <template v-if="holdsSpeakingGrant">
          <div class="font-medium text-green-800">You can speak now</div>
          <div class="text-green-800 mt-1">
            {{ isMuted ? 'You are muted. Unmute when you\'re ready.' : 'Everyone in the call can hear you.' }}
          </div>
          <div v-if="!capabilities.canSendVideo" class="text-gray-700 mt-1">{{ ANONYMOUS_AUDIO_ONLY_NOTE }}</div>
        </template>
        <template v-else>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <div class="font-medium text-gray-800">You are watching</div>
              <div class="text-gray-600 mt-1">
                Others in the call can't see or hear you.
                <span v-if="capabilities.canRaiseHand">Raise your hand to ask a counsellor if you can speak.</span>
                <span v-else-if="capabilities.isMinorMember">Speaking in group video isn't available to members under 18 yet.</span>
              </div>
            </div>
            <button
              v-if="capabilities.canRaiseHand"
              type="button"
              class="px-3 py-1.5 text-sm rounded-md border disabled:opacity-50"
              :class="handRaised ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-white border-gray-200 text-gray-700'"
              :disabled="handBusy"
              @click="clickedHand"
            >
              {{ handRaised ? 'lower hand' : 'raise hand' }}
            </button>
          </div>
          <div v-if="handRaised" class="text-amber-800 mt-2">Your hand is raised. A counsellor will see it.</div>
          <div v-if="speakingNotice" class="text-gray-700 mt-2">{{ speakingNotice }}</div>
        </template>
        <div v-if="handError" class="text-red-600 mt-2">{{ handError }}</div>
      </div>

      <!-- The creator of an anonymous group: can speak, never on camera (SCRUM-332). -->
      <div
        v-else-if="capabilities.canSendAudio && !capabilities.canSendVideo"
        class="mb-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700"
      >
        {{ ANONYMOUS_AUDIO_ONLY_NOTE }}
      </div>

      <div
        v-if="showIdleWarning"
        class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
        role="status"
      >
        Your permission to speak ends in {{ idleSecondsRemaining }}s unless you unmute.
      </div>

      <div
        v-if="pendingRevealAction"
        class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm"
        role="alertdialog"
        aria-labelledby="video-reveal-title"
      >
        <div id="video-reveal-title" class="font-medium text-amber-900">Before you start speaking</div>
        <div class="text-amber-900 mt-1">
          Everyone in this call will hear your voice and see your name. If you turn your camera on, they will also see your face.
        </div>
        <div class="flex gap-3 mt-3">
          <button
            type="button"
            class="px-3 py-1.5 text-sm rounded-md border border-gray-800 bg-gray-800 text-white hover:bg-gray-700"
            @click="confirmReveal"
          >
            {{ pendingRevealAction === 'camera' ? 'turn camera on' : 'unmute' }}
          </button>
          <button
            type="button"
            class="px-3 py-1.5 text-sm rounded-md border border-gray-200 bg-white text-gray-700"
            @click="pendingRevealAction = null"
          >
            cancel
          </button>
        </div>
      </div>

      <!-- TT-3.2f-j/SCRUM-327: counsellor-only hand-raise queue and current speakers. -->
      <div
        v-if="capabilities.canModerate"
        class="mb-3 rounded-md border border-gray-200 p-3 text-sm"
        data-testid="video-moderation-queue"
      >
        <div v-if="moderationError" class="text-red-600 mb-2">{{ moderationError }}</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <div class="font-medium text-gray-800 mb-2">Raised hands</div>
            <div v-if="!raisedHandUserIds.length" class="text-gray-500">No raised hands.</div>
            <ul v-else class="space-y-2">
              <li v-for="userId in raisedHandUserIds" :key="`hand-${userId}`" class="flex items-center justify-between gap-2">
                <span class="text-gray-700 truncate">{{ labelForUserId(userId) }}</span>
                <button
                  type="button"
                  class="shrink-0 px-2 py-1 text-xs rounded-md border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                  :disabled="moderatingUserId === userId"
                  @click="clickedModerate(userId, 'grant')"
                >
                  {{ moderatingUserId === userId ? 'letting speak…' : 'let speak' }}
                </button>
              </li>
            </ul>
          </div>
          <div>
            <div class="font-medium text-gray-800 mb-2">Speaking</div>
            <div v-if="!speakerUserIds.length" class="text-gray-500">Only counsellors and the group's creator can speak.</div>
            <ul v-else class="space-y-2">
              <li v-for="userId in speakerUserIds" :key="`speaker-${userId}`" class="flex items-center justify-between gap-2">
                <span class="text-gray-700 truncate">{{ labelForUserId(userId) }}</span>
                <button
                  type="button"
                  class="shrink-0 px-2 py-1 text-xs rounded-md border border-gray-200 bg-white text-gray-700 hover:bg-red-50 hover:text-red-700 disabled:opacity-50"
                  :disabled="moderatingUserId === userId"
                  @click="clickedModerate(userId, 'revoke')"
                >
                  {{ moderatingUserId === userId ? 'stopping…' : 'stop' }}
                </button>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Spotlight: the focused tile large, everyone else in a strip beside it that scrolls
           vertically. The strip's height is pinned to the focused tile's row (absolute inset-0
           inside the grid cell), so a long strip scrolls instead of stretching the layout. -->
      <div
        v-if="focusedParticipant && otherParticipants.length"
        class="grid grid-cols-[minmax(0,1fr)_7rem] sm:grid-cols-[minmax(0,1fr)_11rem] gap-3"
        data-testid="video-spotlight"
      >
        <VideoTile
          :participant="focusedParticipant"
          :label="participantLabel(focusedParticipant)"
          :video-ref="setVideoElement"
          is-focused
          can-focus
          :can-remove="canRemove(focusedParticipant)"
          :removing="removingParticipantId === focusedParticipant.userId"
          @toggle-focus="toggleFocus(focusedParticipant)"
          @remove="clickedRemove(focusedParticipant)"
        />
        <div class="relative">
          <div class="absolute inset-0 overflow-y-auto space-y-2 pr-1" aria-label="other participants">
            <VideoTile
              v-for="participant in otherParticipants"
              :key="participant.id"
              :participant="participant"
              :label="participantLabel(participant)"
              :video-ref="setVideoElement"
              compact
              can-focus
              @toggle-focus="toggleFocus(participant)"
            />
          </div>
        </div>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <VideoTile
          v-for="participant in participants"
          :key="participant.id"
          :participant="participant"
          :label="participantLabel(participant)"
          :video-ref="setVideoElement"
          :can-focus="canFocus"
          :can-remove="canRemove(participant)"
          :removing="removingParticipantId === participant.userId"
          @toggle-focus="toggleFocus(participant)"
          @remove="clickedRemove(participant)"
        />
      </div>

      <div v-if="mediaError" class="text-sm text-red-600 mt-3 text-center" role="alert">{{ mediaError }}</div>

      <!-- Only the controls this viewer can actually use -- a receive-only member sees none until
           a counsellor lets them speak, and an anonymous speaker never gets a camera button. -->
      <div v-if="capabilities.canSendAudio || capabilities.canSendVideo" class="flex items-center justify-center gap-3 mt-4">
        <button
          v-if="capabilities.canSendAudio"
          type="button"
          class="px-4 py-2 text-sm rounded-md border disabled:opacity-50"
          :disabled="mediaBusy"
          :class="isMuted ? 'bg-red-50 border-red-200 text-red-700' : 'bg-white border-gray-200 text-gray-700'"
          @click="clickedMute"
        >
          {{ isMuted ? 'unmute' : 'mute' }}
        </button>
        <button
          v-if="capabilities.canSendVideo"
          type="button"
          class="px-4 py-2 text-sm rounded-md border disabled:opacity-50"
          :disabled="mediaBusy"
          :class="!isCameraOn ? 'bg-red-50 border-red-200 text-red-700' : 'bg-white border-gray-200 text-gray-700'"
          @click="clickedCamera"
        >
          {{ isCameraOn ? 'turn camera off' : 'turn camera on' }}
        </button>
      </div>
    </div>
  </div>
</template>
