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
    </div>
  </div>
</template>

<script setup>
defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
})
</script>