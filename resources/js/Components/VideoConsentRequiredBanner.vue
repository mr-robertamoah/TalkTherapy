<script setup>
// TT-3.1e-f/SCRUM-285: mirrors VideoPaymentRequiredBanner.vue's own treatment for consistency --
// a specific, non-generic explanation (per the product-owner's own requirement) rather than the
// error+retry UI, which would be misleading here: retrying does nothing until a guardian actually
// grants consent on this same therapy page's Video Consent tab.
defineProps({
  message: { type: String, default: 'Guardian video consent is required before this account can join video for this session.' },
})
</script>

<template>
  <div class="p-6 bg-amber-50 border border-amber-200 rounded-lg text-center">
    <div class="text-amber-900 font-semibold mb-1">Guardian consent needed</div>
    <div class="text-sm text-amber-800">{{ message }}</div>
    <div class="text-xs text-amber-700 mt-2">Ask a guardian to approve video access from the "video consent" section of this therapy's page.</div>
  </div>
</template>
