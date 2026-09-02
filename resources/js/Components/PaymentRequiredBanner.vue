<script setup>
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import usePayment from '@/Composables/usePayment';

// SCRUM-221/TT-7.5a: a client blocked by the strict payment gate (TherapyController's
// PaymentRequiredException redirect) lands here rather than the therapy page itself -- this is
// the "path back to the Pay flow" the ticket requires, deliberately distinct from the generic
// red "failed" toast every other flashed message triggers (see HomeController::goHome()).
const props = defineProps({
  message: { type: String, default: '' },
  therapyId: { default: null },
})

// Only ever needs the bare therapy id (never a full resource, unlike this composable's other
// callers) -- passing a null ref is fine since initiate() itself never touches `therapy`.
const { initiating, initiate } = usePayment(ref(null))
const error = ref('')

async function payNow() {
  if (!props.therapyId) return

  error.value = ''

  try {
    await initiate('transactions.initiate.therapy', props.therapyId)
  } catch (err) {
    error.value = err.message
  }
}
</script>

<template>
  <div v-if="therapyId" class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
    <div class="p-6 bg-blue-50 border border-blue-200 rounded-lg text-center">
      <div class="text-blue-900 font-semibold mb-1">Payment required to continue</div>
      <div class="text-sm text-blue-800 mb-4">{{ message || 'This therapy requires payment before you can continue.' }}</div>
      <PrimaryButton :disabled="initiating" @click="payNow">{{ initiating ? 'redirecting…' : 'pay now' }}</PrimaryButton>
      <div v-if="error" class="text-sm text-red-600 mt-2">{{ error }}</div>
    </div>
  </div>
</template>
