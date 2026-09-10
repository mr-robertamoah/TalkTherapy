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
           this stays accurate instead of permanently reading "Paid" after the money's gone back. -->
      <div v-else-if="therapy.paymentData.per === 'PER_THERAPY' && therapy.paymentStatus === 'SUCCESS'" class="text-sm font-semibold" :class="therapy.refundStatus === 'SUCCESS' ? 'text-gray-600' : 'text-green-700'">
        {{ therapy.refundStatus === 'SUCCESS' ? 'Refunded' : 'Paid' }}
      </div>
      <div class="relative" v-else-if="canPay">
        <FormLoader class="mx-auto" :show="initiating" :text="'starting your payment'" />
        <PrimaryButton
          :disabled="initiating"
          @click="clickedPay"
          :class="isRetryStatus(therapy.paymentStatus) ? 'bg-amber-600 hover:bg-amber-700' : ''"
          >{{ isRetryStatus(therapy.paymentStatus) ? 'try payment again' : 'pay now' }}</PrimaryButton
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

    <!-- TT-7.7b/SCRUM-250: client-only, PER_THERAPY -- request/pending-status UI for a paid,
         not-yet-refunded engagement. Deliberately independent of the "Paid" block above so a
         REJECTED prior request still lets the client ask again (eligible-again per
         EnsureTransactionIsRefundEligibleAction, which only blocks on an active/pending one). -->
    <div v-if="therapyType !== 'group' && therapy.paymentData.per === 'PER_THERAPY' && (canRequestRefund(therapy, computedIsParticipant, computedIsCounsellor) || therapy.refundRequestStatus)" class="mt-4 pt-4 border-t border-gray-200">
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
         there is no client-facing "edit therapy" surface this could otherwise live on. GroupTherapy
         excluded (TT-7.5b). -->
    <div
      v-if="therapyType !== 'group' && computedIsCounsellor && therapy.paymentType === 'PAID'"
      class="mt-4 pt-4 border-t border-gray-200"
    >
      <label class="flex items-center">
        <Checkbox :checked="strictPaymentGate" @update:checked="onToggleStrictGate" :disabled="savingStrictGate" />
        <span class="ms-2 text-sm text-gray-600">Require payment before the client can access this therapy.</span>
      </label>
      <div class="mt-1 text-xs text-gray-500">
        When on, the client must complete payment before they can access this therapy (or, for a per-session therapy, each session). When off (default), the client can access it while payment is still pending.
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
const { initiating, requestingRefund, canPayForTherapy, canRequestRefund, payForTherapy, requestRefund, paymentStatusLabel, isRetryStatus } = usePayment(toRef(props, 'therapy'), props.therapyType)

const canPay = computed(() => canPayForTherapy(props.computedIsParticipant, props.computedIsCounsellor))

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

// Inertia's router.patch(), not plain axios -- TherapyController::updateTherapy() responds with
// Redirect::back() (a 302, not JSON), which a raw axios request isn't set up to follow correctly
// (it surfaced as ERR_TOO_MANY_REDIRECTS in manual testing). Inertia's router handles that
// response as the partial reload it's meant to be, matching how UpdateIndividualTherapyFormModal.vue
// already submits to this same endpoint via useForm().patch() rather than axios.
function onToggleStrictGate(checked) {
  const previous = strictPaymentGate.value
  strictPaymentGate.value = checked
  savingStrictGate.value = true

  router.patch(route('therapies.strict_payment_gate.update', { therapyId: props.therapy.id }), {
    strictPaymentGate: checked,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      setSuccessAlertData({
        message: checked
          ? 'Strict payment gate enabled -- the client must pay before continuing.'
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
</script>