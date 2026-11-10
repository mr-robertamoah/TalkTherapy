<script setup>
// TT-3.1c/SCRUM-276: distinct from PaymentRequiredBanner.vue on purpose -- that component's
// payableId/payableType contract and "pay now" CTA assume a therapy/group-level Paystack
// redirect target, which a session-level video-join 402 doesn't have (the actual pay action, when
// PER_SESSION applies, already exists elsewhere on this same page via usePayment.js's own
// session-payment flow). This banner only ever renders the backend's own message, matching
// PaymentRequiredBanner.vue's visual treatment for consistency, with no CTA of its own.
defineProps({
  message: { type: String, default: 'Payment is required to access video for this session.' },
})
</script>

<template>
  <div class="p-6 bg-blue-50 border border-blue-200 rounded-lg text-center">
    <div class="text-blue-900 font-semibold mb-1">Payment required for video</div>
    <div class="text-sm text-blue-800">{{ message }}</div>
  </div>
</template>
