<script setup>
import UnifiedTherapy from '@/Pages/UnifiedTherapy.vue'

const props = defineProps({
  therapy: { default: null },
  recentSessions: { default: null },
  recentTopics: { default: null },
  pendingRequest: { default: null },
});
</script>

<template>
  <UnifiedTherapy
    :therapy="therapy"
    therapy-type="group"
    :recent-sessions="recentSessions"
    :recent-topics="recentTopics"
    :pending-request="pendingRequest"
  />
</template>