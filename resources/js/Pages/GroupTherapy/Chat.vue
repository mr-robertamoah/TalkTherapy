<script setup>
import TherapyChat from '@/Pages/TherapyChat.vue'

const props = defineProps({
  therapy: { default: null },
});
</script>

<template>
  <TherapyChat
    :therapy="therapy"
    therapy-type="group"
  />
</template>
