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

      <div v-if="therapy.paymentData.per === 'PER_THERAPY' && therapy.paymentStatus === 'SUCCESS'" class="text-sm text-green-700 font-semibold">
        Paid
      </div>
      <div class="relative" v-else-if="canPay">
        <FormLoader class="mx-auto" :show="initiating" :text="'starting your payment'" />
        <PrimaryButton :disabled="initiating" @click="clickedPay">pay now</PrimaryButton>
      </div>
      <div
        v-else-if="therapyType !== 'group' && therapy.paymentData.per === 'PER_THERAPY' && computedIsCounsellor"
        class="text-sm font-semibold"
        :class="[therapy.paymentStatus === 'FAILED' ? 'text-red-600' : 'text-gray-600']"
      >
        {{ paymentStatusLabel(therapy.paymentStatus) }}
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
import { computed, toRef } from 'vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import Alert from '@/Components/Alert.vue'
import useAlert from '@/Composables/useAlert'
import usePayment from '@/Composables/usePayment'

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  computedIsParticipant: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
})

const { alertData, clearAlertData, setFailedAlertData } = useAlert()
const { initiating, canPayForTherapy, payForTherapy, paymentStatusLabel } = usePayment(toRef(props, 'therapy'), props.therapyType)

const canPay = computed(() => canPayForTherapy(props.computedIsParticipant, props.computedIsCounsellor))

async function clickedPay() {
  try {
    await payForTherapy()
  } catch (err) {
    setFailedAlertData({ message: err.message })
  }
}
</script>