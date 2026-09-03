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
      <div v-else-if="therapy.paymentData.per === 'PER_THERAPY' && therapy.paymentStatus === 'SUCCESS'" class="text-sm text-green-700 font-semibold">
        Paid
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
import Checkbox from '@/Components/Checkbox.vue'
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
const { initiating, canPayForTherapy, payForTherapy, paymentStatusLabel, isRetryStatus } = usePayment(toRef(props, 'therapy'), props.therapyType)

const canPay = computed(() => canPayForTherapy(props.computedIsParticipant, props.computedIsCounsellor))

async function clickedPay() {
  try {
    await payForTherapy()
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