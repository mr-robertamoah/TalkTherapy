<template>
  <!-- Recent Sessions -->
  <div id="therapy-sessions-id" class="relative"></div>
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="p-6 overflow-hidden shadow-sm sm:rounded-lg my-8 bg-slate-200">
      <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">
        Most Recent Sessions
      </div>
      <div
        v-if="recentSessions?.length"
        class="flex space-x-3 justify-start items-center p-2 overflow-hidden overflow-x-auto w-full"
      >
        <SessionBadge
          v-for="(item, idx) in recentSessions"
          :key="idx"
          :session="item"
          :therapy="therapy"
          :listen="false"
          @on-update="(item) => $emit('on-update', item)"
          @on-delete="(item) => $emit('on-delete', item)"
          class="w-[60%] min-w-[300px] shrink-0"
        />
      </div>
      <div v-else class="text-sm text-center text-gray-600 my-2">
        no recent sessions
      </div>
    </div>
  </div>

  <!-- Recent Topics -->
  <div id="therapy-topics-id" class="relative"></div>
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="p-6 overflow-hidden shadow-sm sm:rounded-lg my-8 bg-slate-200">
      <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">
        Most Recent Topics
      </div>
      <div
        v-if="recentTopics?.length"
        class="flex space-x-3 justify-start items-center p-2 overflow-hidden overflow-x-auto w-full"
      >
        <TopicBadge
          v-for="(item, idx) in recentTopics"
          :key="idx"
          :topic="item"
          :therapy="therapy"
          @on-update="(item) => $emit('on-update', item)"
          @on-delete="(item) => $emit('on-delete', item)"
          class="w-[60%] shrink-0 bg-slate-200 rounded"
        />
      </div>
      <div v-else class="text-sm text-center text-gray-600 my-2">
        no recent topics
      </div>
    </div>
  </div>
</template>

<script setup>
import SessionBadge from '@/Components/SessionBadge.vue'
import TopicBadge from '@/Components/TopicBadge.vue'

defineProps({
  recentSessions: { default: () => [] },
  recentTopics: { default: () => [] },
  therapy: { default: null },
})

defineEmits(['on-update', 'on-delete'])
</script>