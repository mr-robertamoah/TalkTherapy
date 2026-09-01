<script setup>
import { computed } from 'vue'
import useUtilities from '@/Composables/useUtilities'
import useCalendar from '@/Composables/useCalendar'

// SCRUM-213/TT-2.6b: drill-through only, deliberately no session-mutation actions here (accept/
// reject/end/etc. already live on the underlying Therapy/GroupTherapy page) -- mirrors
// SessionBadge.vue's status color/label conventions at a smaller, calendar-cell-appropriate scale.
const props = defineProps({
  event: { required: true },
})

defineEmits(['click'])

const { getReadableStatus } = useUtilities()
const { toLocalDate, format } = useCalendar()

function getStatusColor(status) {
  const colors = {
    PENDING: 'bg-yellow-500',
    IN_SESSION: 'bg-green-500',
    IN_SESSION_CONFIRMATION: 'bg-blue-500',
    HELD: 'bg-gray-500',
    HELD_CONFIRMATION: 'bg-purple-500',
    FAILED: 'bg-red-500',
    ABANDONED: 'bg-orange-500',
  }
  return colors[status] || 'bg-gray-500'
}

const startTimeLabel = computed(() => {
  const date = toLocalDate(props.event.startTime)
  return date ? format(date, 'h:mm a') : ''
})
</script>

<template>
  <div
    @click="$emit('click', event)"
    class="rounded p-1.5 text-xs cursor-pointer select-none hover:opacity-80 transition-opacity duration-150"
    :class="getStatusColor(event.status)"
    :title="`${event.name} — ${getReadableStatus(event.status)}`"
  >
    <div class="flex items-center justify-between gap-1 text-white">
      <span class="font-semibold shrink-0">{{ startTimeLabel }}</span>
      <span
        class="text-[10px] uppercase tracking-wide px-1 rounded shrink-0"
        :class="event.forType === 'group' ? 'bg-white/30' : 'bg-black/20'"
      >{{ event.forType === 'group' ? 'group' : 'individual' }}</span>
    </div>
    <div class="text-white truncate">{{ event.name }}</div>
    <div class="text-white/80 truncate text-[11px]">{{ event.for?.name }}</div>
  </div>
</template>
