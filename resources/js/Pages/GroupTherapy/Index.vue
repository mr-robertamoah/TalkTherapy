<script setup>
import UnifiedTherapy from '@/Pages/UnifiedTherapy.vue'

const props = defineProps({
  therapy: { default: null },
  recentSessions: { default: null },
  recentTopics: { default: null },
  pendingRequest: { default: null },
  pendingMembershipRequest: { default: null },
  linkInvitation: { default: null },
  preview: { default: null },
  isFormerMember: { type: Boolean, default: false },
});
</script>

<template>
  <UnifiedTherapy
    :therapy="therapy"
    therapy-type="group"
    :recent-sessions="recentSessions"
    :recent-topics="recentTopics"
    :pending-request="pendingRequest"
    :pending-membership-request="pendingMembershipRequest"
    :link-invitation="linkInvitation"
    :preview="preview"
    :is-former-member="isFormerMember"
  />
</template>