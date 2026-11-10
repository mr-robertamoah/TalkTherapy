<script setup>
// TT-3.2f-j/SCRUM-327: one participant's tile, shared by VideoCallPanel.vue's grid and spotlight
// layouts so the three places a tile appears (grid, spotlight, side strip) can't drift apart.
// Purely presentational -- the panel owns every action; this only emits.
defineProps({
  participant: { type: Object, required: true },
  label: { type: String, required: true },
  // Called with (participantId, element) -- VideoCallPanel.vue's setVideoElement(), which attaches
  // the provider's stream to whichever <video> element currently renders this participant.
  videoRef: { type: Function, required: true },
  // A side-strip tile: only the focus control, no remove (there's no room -- focus them first).
  compact: { type: Boolean, default: false },
  isFocused: { type: Boolean, default: false },
  canFocus: { type: Boolean, default: false },
  canRemove: { type: Boolean, default: false },
  removing: { type: Boolean, default: false },
})

defineEmits(['toggle-focus', 'remove'])
</script>

<template>
  <div class="relative bg-gray-900 rounded-lg overflow-hidden aspect-video">
    <video
      :ref="(el) => videoRef(participant.id, el)"
      autoplay
      playsinline
      :muted="participant.isLocal"
      class="w-full h-full object-cover"
    />
    <span
      class="absolute bottom-1.5 left-1.5 max-w-[90%] truncate text-xs text-white bg-gray-900 px-2 py-0.5 rounded"
    >
      {{ label }}
    </span>
    <div class="absolute top-1.5 right-1.5 flex gap-1">
      <button
        v-if="canFocus"
        type="button"
        class="text-xs text-white bg-gray-900 hover:bg-gray-700 px-2 py-0.5 rounded"
        :aria-label="isFocused ? 'show everyone' : `focus on ${label}`"
        @click="$emit('toggle-focus')"
      >
        {{ isFocused ? 'show all' : 'focus' }}
      </button>
      <button
        v-if="canRemove && !compact"
        type="button"
        class="text-xs text-white bg-gray-900 hover:bg-red-700 px-2 py-0.5 rounded disabled:opacity-50"
        :disabled="removing"
        @click="$emit('remove')"
      >
        {{ removing ? 'removing…' : 'remove' }}
      </button>
    </div>
  </div>
</template>
