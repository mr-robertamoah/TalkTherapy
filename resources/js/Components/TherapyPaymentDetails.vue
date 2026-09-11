<template>
  <div class="bg-white p-6 shrink-0 w-full" id="therapy_payment_details">
    <div class="text-gray-600 tracking-wide font-semibold capitalize">
      Payment per {{ therapy.per }}
    </div>
    <div class="my-4">
      <div class="flex justify-start items-center mb-4">
        <div
          class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end"
        >
          Online Amount:
        </div>
        <div class="p-2 border-stone-600 text-start min-w-[120px]">
          {{
            therapy.paymentData.currency +
            " " +
            therapy.paymentData.amount
          }}
        </div>
      </div>
      <div
        class="flex justify-start items-center mb-4"
        v-if="therapy.allowInPerson"
      >
        <div
          class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end"
        >
          In-person Amount:
        </div>
        <div class="p-2 border-stone-600 text-start min-w-[120px]">
          {{
            therapy.paymentData.currency +
              " " +
              (therapy.paymentData.inPersonAmount ?? therapy.paymentData.amount)
          }}
        </div>
      </div>
      
      <!-- Group therapy payment sharing info -->
      <template v-if="therapyType === 'group' && therapy.paymentData.shareEqually === false">
        <div class="flex justify-start items-center mb-4">
          <div
            class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end"
          >
            Counsellor Share:
          </div>
          <div class="p-2 border-stone-600 text-start min-w-[120px]">
            {{ therapy.paymentData.sharePercentage }}%
          </div>
        </div>
      </template>

      <div v-if="therapy.paymentData.per === 'PER_THERAPY' && therapy.orgRetainerCoverage" class="text-sm text-gray-600">
        This therapy is covered under {{ therapy.orgRetainerCoverage.organizationName }}'s plan with TalkTherapy -- no payment needed from you.
      </div>
      <!-- TT-7.7e/SCRUM-253: paymentStatus itself never flips off SUCCESS on refund (refunds live
           in their own table, see TT-7.7a's decision-log entry) -- refundStatus is checked here so
           this stays accurate instead of permanently reading "Paid" after the money's gone back.
           TT-7.4d-b/SCRUM-259: viewerScopedPaymentStatus() reads MY OWN status for a group therapy
           instead of therapy.paymentStatus's "paid by ANY member" -- otherwise a member who hasn't
           paid would see "Paid" here the moment any other member did. -->
      <div v-else-if="therapy.paymentData.per === 'PER_THERAPY' && viewerScopedPaymentStatus(therapy) === 'SUCCESS'" class="text-sm font-semibold" :class="therapy.refundStatus === 'SUCCESS' ? 'text-gray-600' : 'text-green-700'">
        {{ therapy.refundStatus === 'SUCCESS' ? 'Refunded' : 'Paid' }}
      </div>
      <div class="relative" v-else-if="canPay">
        <FormLoader class="mx-auto" :show="initiating" :text="'starting your payment'" />
        <PrimaryButton
          :disabled="initiating"
          @click="clickedPay"
          :class="isRetryStatus(viewerScopedPaymentStatus(therapy)) ? 'bg-amber-600 hover:bg-amber-700' : ''"
          >{{ isRetryStatus(viewerScopedPaymentStatus(therapy)) ? 'try payment again' : 'pay now' }}</PrimaryButton
        >
      </div>
      <div
        v-else-if="therapyType !== 'group' && therapy.paymentData.per === 'PER_THERAPY' && computedIsCounsellor"
        class="text-sm font-semibold"
        :class="[therapy.paymentStatus === 'FAILED' ? 'text-red-600' : 'text-gray-600']"
      >
        {{ paymentStatusLabel(therapy.paymentStatus) }}
      </div>
    </div>

    <!-- TT-7.4d-d/SCRUM-261: counsellor-only, PER_THERAPY group roster -- `therapy.paymentRoster`
         is only ever present in the response at all for this group's own counsellor (see
         GroupTherapyResource's own guard), so the `computedIsCounsellor` check here is a display
         nicety, not the actual authorization boundary. Deliberately real member identity (not
         anonymized), per this ticket's own scoped exception to the anonymity rule -- logged in
         documentation/decision-log.md. -->
    <div v-if="therapyType === 'group' && computedIsCounsellor && therapy.paymentRoster" class="mt-4 pt-4 border-t border-gray-200">
      <div class="text-gray-600 tracking-wide font-semibold mb-2">Member Payment Status</div>
      <div
        v-for="member in therapy.paymentRoster"
        :key="member.id"
        class="flex justify-between items-center py-1 border-b border-gray-100 last:border-b-0"
      >
        <div class="text-sm text-gray-700">{{ member.fullName }} <span v-if="member.username" class="text-gray-400">@{{ member.username }}</span></div>
        <div class="text-sm font-semibold" :class="member.paymentStatus === 'FAILED' ? 'text-red-600' : (member.paymentStatus === 'SUCCESS' ? 'text-green-700' : 'text-gray-600')">
          {{ paymentStatusLabel(member.paymentStatus) }}
        </div>
      </div>
    </div>

    <!-- TT-7.7b/SCRUM-250: client-only, PER_THERAPY -- request/pending-status UI for a paid,
         not-yet-refunded engagement. Deliberately independent of the "Paid" block above so a
         REJECTED prior request still lets the client ask again (eligible-again per
         EnsureTransactionIsRefundEligibleAction, which only blocks on an active/pending one).
         TT-7.4d-c/SCRUM-260: no longer individual-Therapy-only -- canRequestRefund()/
         therapy.refundRequestStatus are both viewer-scoped for a GroupTherapy too now. -->
    <div v-if="therapy.paymentData.per === 'PER_THERAPY' && (canRequestRefund(therapy, computedIsParticipant, computedIsCounsellor) || therapy.refundRequestStatus)" class="mt-4 pt-4 border-t border-gray-200">
      <div v-if="therapy.refundRequestStatus === 'PENDING'" class="text-sm text-amber-700 font-semibold">
        Refund requested -- pending admin review.
      </div>
      <template v-else>
        <div v-if="therapy.refundRequestStatus === 'REJECTED'" class="text-sm text-gray-500 mb-2">
          Your previous refund request was declined. You may request again below.
        </div>
        <PrimaryButton v-if="!showRefundForm" @click="showRefundForm = true" class="bg-gray-600 hover:bg-gray-700">request a refund</PrimaryButton>
        <div v-else class="relative">
          <FormLoader class="mx-auto" :show="requestingRefund" :text="'submitting your refund request'" />
          <InputLabel for="refund_reason" value="Why are you requesting a refund?" />
          <TextBox id="refund_reason" v-model="refundReason" class="mt-1 block w-full" rows="3" />
          <InputError :message="refundReasonError" class="mt-1" />
          <div class="mt-2 flex gap-2">
            <PrimaryButton :disabled="requestingRefund" @click="clickedRequestRefund">submit refund request</PrimaryButton>
            <SecondaryButton :disabled="requestingRefund" @click="cancelRefundRequest">cancel</SecondaryButton>
          </div>
        </div>
      </template>
    </div>

    <!-- SCRUM-221/TT-7.5a: counsellor-only -- the therapy's own client can set this at creation
         but is never authorized to change it afterward (EnsureCanSetStrictPaymentGateAction), and
         there is no client-facing "edit therapy" surface this could otherwise live on.
         TT-7.5b-b5/SCRUM-269: widened to GroupTherapy too -- "counsellor" here means any ACTIVE
         counsellor on the group (b0's own toggle-authority decision), not just the addedby;
         `computedIsCounsellor` is only a display nicety (same as the paymentRoster block above),
         the real authorization boundary is EnsureCanSetGroupTherapyPaymentGateAction server-side. -->
    <div
      v-if="computedIsCounsellor && therapy.paymentType === 'PAID'"
      class="mt-4 pt-4 border-t border-gray-200"
    >
      <label class="flex items-center">
        <Checkbox :checked="strictPaymentGate" @update:checked="onToggleStrictGate" :disabled="savingStrictGate" />
        <span class="ms-2 text-sm text-gray-600">Require payment before {{ subjectNoun }} can access this {{ gateNoun }}.</span>
      </label>
      <div class="mt-1 text-xs text-gray-500">
        When on, {{ eachSubjectNoun }} must complete payment before they can access {{ theGateNoun }} (or, for a per-session {{ gateNoun }}, each session). When off (default), {{ subjectNoun }} can access it while payment is still pending.
      </div>

      <!-- TT-7.5b-b5/SCRUM-269: GroupTherapy-only sibling setting (b1's own allowFreeHistoricalAccess). -->
      <div v-if="therapyType === 'group'" class="mt-4">
        <label class="flex items-center">
          <Checkbox :checked="allowFreeHistoricalAccess" @update:checked="onToggleAllowFreeHistoricalAccess" :disabled="savingHistoricalAccess" />
          <span class="ms-2 text-sm text-gray-600">Let new joiners see content posted before they joined for free.</span>
        </label>
        <div class="mt-1 text-xs text-gray-500">
          When on (default), a member who joins after payment is required can still see resources/messages posted before they joined without paying -- only new content requires payment. When off, everything requires payment regardless of when a member joined.
        </div>
      </div>
    </div>

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
import { computed, ref, toRef, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Checkbox from '@/Components/Checkbox.vue'
import TextBox from '@/Components/TextBox.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import Alert from '@/Components/Alert.vue'
import useAlert from '@/Composables/useAlert'
import usePayment from '@/Composables/usePayment'

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  computedIsParticipant: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()
const { initiating, requestingRefund, canPayForTherapy, canRequestRefund, payForTherapy, requestRefund, paymentStatusLabel, isRetryStatus, viewerScopedPaymentStatus } = usePayment(toRef(props, 'therapy'), props.therapyType)

const canPay = computed(() => canPayForTherapy(props.computedIsParticipant, props.computedIsCounsellor))

// TT-7.5b-b5/SCRUM-269 (reviewer suggestion): factored out of what were four inline ternaries
// repeated across the strict-gate toggle's copy below, purely for readability.
const subjectNoun = computed(() => props.therapyType === 'group' ? 'a member' : 'the client')
const eachSubjectNoun = computed(() => props.therapyType === 'group' ? 'each member' : 'the client')
const gateNoun = computed(() => props.therapyType === 'group' ? 'group' : 'therapy')
const theGateNoun = computed(() => props.therapyType === 'group' ? 'the group' : 'this therapy')

async function clickedPay() {
  try {
    await payForTherapy()
  } catch (err) {
    setFailedAlertData({ message: err.message })
  }
}

// TT-7.7b/SCRUM-250
const showRefundForm = ref(false)
const refundReason = ref('')
const refundReasonError = ref('')

function cancelRefundRequest() {
  showRefundForm.value = false
  refundReason.value = ''
  refundReasonError.value = ''
}

async function clickedRequestRefund() {
  refundReasonError.value = ''

  if (refundReason.value.trim().length < 10) {
    refundReasonError.value = 'Please provide at least 10 characters explaining why you are requesting a refund.'
    return
  }

  try {
    await requestRefund(props.therapy.transactionId, refundReason.value)
    showRefundForm.value = false
    refundReason.value = ''
    setSuccessAlertData({ message: 'Your refund request has been submitted for review.', time: 6000 })
    // Raw axios call above doesn't refresh Inertia props on its own -- reload just `therapy` so
    // therapy.refundRequestStatus reflects the newly-created PENDING request without a full
    // page navigation, mirroring onToggleStrictGate's own props-freshness expectation.
    router.reload({ only: ['therapy'], preserveScroll: true })
  } catch (err) {
    setFailedAlertData({ message: err.message })
  }
}

// SCRUM-221/TT-7.5a: counsellor-only strict/trust payment-gate toggle.
const strictPaymentGate = ref(!!props.therapy?.paymentData?.strictPaymentGate)
const savingStrictGate = ref(false)

watch(() => props.therapy?.paymentData?.strictPaymentGate, (value) => {
  strictPaymentGate.value = !!value
})

// TT-7.5b-b5/SCRUM-269: GroupTherapy-only sibling toggle (b1's allowFreeHistoricalAccess).
// Defaults to true, matching CreateGroupTherapyAction/UpdateGroupTherapyAction's own default.
const allowFreeHistoricalAccess = ref(props.therapy?.paymentData?.allowFreeHistoricalAccess ?? true)
const savingHistoricalAccess = ref(false)

watch(() => props.therapy?.paymentData?.allowFreeHistoricalAccess, (value) => {
  allowFreeHistoricalAccess.value = value ?? true
})

// Inertia's router.patch(), not plain axios -- both TherapyController::updateStrictPaymentGate()
// and GroupTherapyService::updateGroupTherapyPaymentGate() respond with Redirect::back() (a 302,
// not JSON), which a raw axios request isn't set up to follow correctly (it surfaced as
// ERR_TOO_MANY_REDIRECTS in manual testing during TT-7.5a). Inertia's router handles that response
// as the partial reload it's meant to be, matching how UpdateIndividualTherapyFormModal.vue already
// submits to this same pattern via useForm().patch() rather than axios.
function onToggleStrictGate(checked) {
  const previous = strictPaymentGate.value
  strictPaymentGate.value = checked
  savingStrictGate.value = true

  const routeName = props.therapyType === 'group'
    ? 'group.therapies.payment_gate.update'
    : 'therapies.strict_payment_gate.update'
  const routeParams = props.therapyType === 'group'
    ? { groupTherapyId: props.therapy.id }
    : { therapyId: props.therapy.id }

  router.patch(route(routeName, routeParams), {
    strictPaymentGate: checked,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      setSuccessAlertData({
        message: checked
          ? `Strict payment gate enabled -- ${props.therapyType === 'group' ? 'members' : 'the client'} must pay before continuing.`
          : 'Strict payment gate disabled -- trust-based access restored.',
        time: 6000,
      })
    },
    onError: (errors) => {
      strictPaymentGate.value = previous
      setFailedAlertData({
        message: errors?.alert || errors?.strictPaymentGate || 'Could not update the payment gate setting. Please try again.',
      })
    },
    onFinish: () => {
      savingStrictGate.value = false
    },
  })
}

// TT-7.5b-b5/SCRUM-269: GroupTherapy-only -- deliberately always posts to the dedicated
// group.therapies.payment_gate.update endpoint (never the general group.therapies.update one),
// same reasoning as onToggleStrictGate above for the group branch.
function onToggleAllowFreeHistoricalAccess(checked) {
  const previous = allowFreeHistoricalAccess.value
  allowFreeHistoricalAccess.value = checked
  savingHistoricalAccess.value = true

  router.patch(route('group.therapies.payment_gate.update', { groupTherapyId: props.therapy.id }), {
    allowFreeHistoricalAccess: checked,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      setSuccessAlertData({
        message: checked
          ? 'Free historical access enabled -- new joiners can see content posted before they joined.'
          : 'Free historical access disabled -- all content now requires payment regardless of join date.',
        time: 6000,
      })
    },
    onError: (errors) => {
      allowFreeHistoricalAccess.value = previous
      setFailedAlertData({
        message: errors?.alert || errors?.allowFreeHistoricalAccess || 'Could not update the payment gate setting. Please try again.',
      })
    },
    onFinish: () => {
      savingHistoricalAccess.value = false
    },
  })
}
</script>
