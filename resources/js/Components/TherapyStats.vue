<template>
  <div class="bg-white p-6 shrink-0 w-full" id="therapy_stats">
    <div class="text-gray-600 tracking-wide font-semibold">Stats</div>
    <div class="my-4">
      <ActivityBadge
        :name="'total sessions'"
        :value="therapy.sessionsCreated"
        class="my-2 ml-6 mt-4"
      />
      <ActivityBadge
        :name="'held sessions'"
        :value="therapy.sessionsHeld"
        class="my-2 mt-4"
      />
      <ActivityBadge
        :name="'free sessions held'"
        :value="therapy.freeSessions"
        class="my-2 mt-4 ml-6"
      />
      <ActivityBadge
        :name="'paid sessions held'"
        :value="therapy.paidSessions"
        class="my-2 mt-4"
      />
    </div>
  </div>
</template>

<script setup>
import ActivityBadge from '@/Components/ActivityBadge.vue'

defineProps({
  therapy: { default: null },
})
</script>