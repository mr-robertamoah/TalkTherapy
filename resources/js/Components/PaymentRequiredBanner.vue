<script setup>
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import usePayment from '@/Composables/usePayment';

// SCRUM-221/TT-7.5a: a client blocked by the strict payment gate (TherapyController's
// PaymentRequiredException redirect) lands here rather than the therapy page itself -- this is
// the "path back to the Pay flow" the ticket requires, deliberately distinct from the generic
// red "failed" toast every other flashed message triggers (see HomeController::goHome()).
//
// TT-7.5b-b5/SCRUM-269: widened to be payable-type-parameterized rather than forked into a
// separate GroupPaymentRequiredBanner.vue -- GroupTherapyController::redirectForPaymentRequired()
// flashes a sibling `paymentRequiredGroupTherapyId` key (a GroupTherapy id isn't in the same
// id-space as a Therapy id), and this banner now accepts either, choosing the matching
// `transactions.initiate.*` route by `payableType`. Works unchanged from both GroupTherapy/Index.vue
// (today) and UnifiedTherapy.vue, should GroupTherapy's own controller ever consolidate onto it.
const props = defineProps({
  message: { type: String, default: '' },
  payableId: { default: null },
  payableType: { type: String, default: 'individual' }, // 'individual' | 'group'
})

// Only ever needs the bare payable id (never a full resource, unlike this composable's other
// callers) -- passing a null ref is fine since initiate() itself never touches `therapy`.
const { initiating, initiate } = usePayment(ref(null))
const error = ref('')

async function payNow() {
  if (!props.payableId) return

  error.value = ''

  try {
    await initiate(
      props.payableType === 'group' ? 'transactions.initiate.group_therapy' : 'transactions.initiate.therapy',
      props.payableId
    )
  } catch (err) {
    error.value = err.message
  }
}
</script>

<template>
  <div v-if="payableId" class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
    <div class="p-6 bg-blue-50 border border-blue-200 rounded-lg text-center">
      <div class="text-blue-900 font-semibold mb-1">Payment required to continue</div>
      <div class="text-sm text-blue-800 mb-4">{{ message || (payableType === 'group' ? 'This group requires payment before you can continue.' : 'This therapy requires payment before you can continue.') }}</div>
      <PrimaryButton :disabled="initiating" @click="payNow">{{ initiating ? 'redirecting…' : 'pay now' }}</PrimaryButton>
      <div v-if="error" class="text-sm text-red-600 mt-2">{{ error }}</div>
    </div>
  </div>
</template>
