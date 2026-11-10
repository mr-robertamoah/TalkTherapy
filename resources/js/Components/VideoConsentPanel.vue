<template>
  <div v-if="therapy.videoConsent" class="bg-white p-6 w-full">
    <div class="text-gray-600 tracking-wide font-semibold">Video Consent</div>
    <div class="mt-1 mb-4 text-xs text-gray-500">
      This client is under 18. A guardian must approve video access before they can join a video call for this therapy.
    </div>

    <div v-if="canSetMode" class="mb-6">
      <div class="text-sm text-gray-600 mb-2">Consent mode</div>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="option in modeOptions"
          :key="option.value"
          type="button"
          :disabled="savingMode"
          class="px-3 py-1.5 text-sm rounded-md border transition"
          :class="mode === option.value
            ? 'bg-gray-800 text-white border-gray-800'
            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
          @click="onSetMode(option.value)"
        >
          {{ option.label }}
        </button>
      </div>
      <div class="mt-1 text-xs text-gray-500">
        {{ mode === 'PER_SESSION'
          ? 'Consent must be given separately for each session.'
          : 'One approval covers every session in this therapy.' }}
      </div>
    </div>
    <div v-else-if="mode" class="mb-6 text-sm text-gray-600">
      Consent mode: <span class="font-medium">{{ mode === 'PER_SESSION' ? 'per session' : 'per therapy' }}</span>
    </div>

    <div class="mb-4">
      <div v-if="!mode" class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md p-3">
        No consent mode has been set yet.
        {{ canSetMode ? ' Choose one above to get started.' : ' The counsellor or a guardian needs to set one before consent can be given.' }}
      </div>
      <div v-else-if="current" class="text-sm text-green-800 bg-green-50 border border-green-200 rounded-md p-3">
        Approved by {{ current.grantedByName }} on {{ formatDate(current.grantedAt) }}.
      </div>
      <div v-else class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md p-3">
        Video consent has not been given yet.
      </div>
    </div>

    <div v-if="isGuardian" class="flex items-center gap-2 mb-4">
      <PrimaryButton v-if="mode && !current" :disabled="granting" @click="onGrant">approve video access</PrimaryButton>
      <DangerButton v-if="current" :disabled="revoking" @click="() => showModal('revoke-video-consent')">revoke consent</DangerButton>
    </div>

    <div v-if="isGuardian">
      <button type="button" class="text-sm text-gray-600 hover:underline" @click="toggleAuditTrail">
        {{ auditTrailOpen ? 'hide' : 'show' }} consent history
      </button>
      <div v-if="auditTrailOpen" class="mt-3 space-y-2">
        <div v-if="loadingAuditTrail" class="text-sm text-gray-500">loading…</div>
        <div v-else-if="auditTrail.length === 0" class="text-sm text-gray-500">No consent history yet.</div>
        <div v-for="entry in auditTrail" :key="entry.id" class="text-sm border border-gray-100 rounded-md p-2">
          <div v-if="entry.isValid">
            <span class="font-medium">{{ entry.grantedByName }}</span> approved ({{ entry.scope }}) on {{ formatDate(entry.grantedAt) }}
          </div>
          <div v-else class="text-gray-600">
            <span class="font-medium">{{ entry.grantedByName }}</span> approved ({{ entry.scope }}) on {{ formatDate(entry.grantedAt) }} —
            revoked{{ entry.revokedByName ? ` by ${entry.revokedByName}` : ' (guardianship removed)' }} on {{ formatDate(entry.revokedAt) }}
          </div>
        </div>
      </div>
    </div>

    <MiniModal :show="modalData.type === 'revoke-video-consent' && modalData.show" @close="closeModal">
      <div class="select-none">
        <div class="text-red-700 text-center font-bold tracking-wide">Revoke Video Consent</div>
        <hr class="my-2">
        <FormLoader :danger="true" :show="revoking" text="revoking consent" />
        <div class="text-red-700 my-4 w-[90%] mx-auto text-center text-sm">
          This will immediately end any video call currently in progress for this
          {{ mode === 'PER_SESSION' ? 'session' : 'therapy' }}, and the client will need new consent before
          joining video again. Are you sure?
        </div>
        <div class="flex p-4 items-center justify-end mx-auto w-[90%] md:w-[75%]">
          <PrimaryButton @click="closeModal">cancel</PrimaryButton>
          <DangerButton class="ml-2" :disabled="revoking" @click="onRevoke">revoke</DangerButton>
        </div>
      </div>
    </MiniModal>

    <Alert
      :show="alertData.show"
      :type="alertData.type"
      :message="alertData.message"
      :time="alertData.time"
      @close="clearAlertData"
    />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import FormLoader from '@/Components/FormLoader.vue'
import MiniModal from '@/Components/MiniModal.vue'
import Alert from '@/Components/Alert.vue'
import useModal from '@/Composables/useModal'
import useAlert from '@/Composables/useAlert'

// TT-3.1e-f/SCRUM-285: the whole panel is null/hidden unless TherapyResource's own videoConsent
// field is present (a real, still-minor client) -- this component never independently decides
// applicability, only the backend does (matches computedIsCounsellor's own "display-only" role
// elsewhere on this page; the real authorization boundary is always the backend Action).
const props = defineProps({
  therapy: { default: null },
  isCounsellor: { type: Boolean, default: false },
})

const { modalData, showModal, closeModal } = useModal()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const modeOptions = [
  { value: 'PER_THERAPY', label: 'per therapy' },
  { value: 'PER_SESSION', label: 'per session' },
]

// Local, optimistically-updated state -- NOT a computed off props.therapy. This page
// (UnifiedTherapy.vue) snapshots its therapy prop into a local ref once at setup
// (`const therapyRef = ref(props.therapy)`) and never re-syncs it from later Inertia visits, the
// same reason TherapyPaymentDetails.vue's own strictPaymentGate toggle uses a local ref + manual
// optimistic assignment rather than reading back through props after a write -- confirmed via
// Playwright during this ticket's own QA pass (a grant succeeded server-side immediately, but the
// UI kept showing "not yet given" until a full page reload). Mirrors that exact pattern.
const mode = ref(props.therapy?.videoConsent?.mode ?? null)
const current = ref(props.therapy?.videoConsent?.current ?? null)

watch(() => props.therapy?.videoConsent?.mode, (value) => { mode.value = value ?? null })
watch(() => props.therapy?.videoConsent?.current, (value) => { current.value = value ?? null })

const isGuardian = computed(() => !!props.therapy?.videoConsent?.viewerIsGuardian)
// Display-only gate, mirroring the backend's own counsellor-or-guardian rule
// (SetVideoConsentModeAction) -- the real check happens server-side either way.
const canSetMode = computed(() => props.isCounsellor || isGuardian.value)

const savingMode = ref(false)
const granting = ref(false)
const revoking = ref(false)

function onSetMode(value) {
  if (savingMode.value || value === mode.value) return
  const previousMode = mode.value
  const previousCurrent = current.value
  mode.value = value
  // The displayed "current" grant belongs to the OLD scope (therapy vs. a specific session) --
  // switching modes changes which scope is relevant, and this component has no cheap way to
  // learn the new scope's real consent state without a server round trip. Reset to "not yet
  // given" rather than keep showing a grant that may not even apply to the new scope -- the safe,
  // fail-closed default (a guardian re-checking/re-granting is harmless and idempotent; wrongly
  // showing "approved" for a scope that isn't actually covered would not be).
  current.value = null
  savingMode.value = true

  router.patch(route('therapies.video_consent.mode_update', { therapyId: props.therapy.id }), { mode: value }, {
    preserveScroll: true,
    onSuccess: () => setSuccessAlertData({ message: 'Video consent mode updated.' }),
    onError: (errors) => {
      mode.value = previousMode
      current.value = previousCurrent
      setFailedAlertData({ message: errors?.alert || 'Could not update the consent mode. Please try again.' })
    },
    onFinish: () => { savingMode.value = false },
  })
}

function onGrant() {
  if (granting.value) return
  const previous = current.value
  granting.value = true

  router.post(route('therapies.video_consent.grant', { therapyId: props.therapy.id }), {}, {
    preserveScroll: true,
    onSuccess: () => {
      // Optimistic: the actual grant is guaranteed to be attributed to the acting guardian
      // (this viewer), so "approved by you, just now" is accurate even without a fresh
      // server-shaped VideoConsentResource to read back (Redirect::back() carries no JSON body).
      current.value = {
        grantedByName: usePage().props.auth.user?.fullName ?? 'you',
        grantedAt: new Date().toISOString(),
        isValid: true,
      }
      setSuccessAlertData({ message: 'Video consent approved.' })
    },
    onError: (errors) => {
      current.value = previous
      setFailedAlertData({ message: errors?.alert || 'Could not approve video consent. Please try again.' })
    },
    onFinish: () => { granting.value = false },
  })
}

function onRevoke() {
  if (revoking.value) return
  const previous = current.value
  revoking.value = true

  router.delete(route('therapies.video_consent.revoke', { therapyId: props.therapy.id }), {
    preserveScroll: true,
    onSuccess: () => {
      current.value = null
      setSuccessAlertData({ message: 'Video consent revoked. Any active call for this scope has been ended immediately.' })
      closeModal()
    },
    onError: (errors) => {
      current.value = previous
      setFailedAlertData({ message: errors?.alert || 'Could not revoke video consent. Please try again.' })
    },
    onFinish: () => { revoking.value = false },
  })
}

const auditTrailOpen = ref(false)
const loadingAuditTrail = ref(false)
const auditTrail = ref([])

function toggleAuditTrail() {
  auditTrailOpen.value = !auditTrailOpen.value
  if (auditTrailOpen.value && auditTrail.value.length === 0) fetchAuditTrail()
}

function fetchAuditTrail() {
  loadingAuditTrail.value = true
  axios.get(route('therapies.video_consent.audit_trail', { therapyId: props.therapy.id }))
    .then((res) => { auditTrail.value = res.data.data })
    .catch(() => { auditTrail.value = [] })
    .finally(() => { loadingAuditTrail.value = false })
}

function formatDate(value) {
  return value ? new Date(value).toLocaleString() : ''
}
</script>
