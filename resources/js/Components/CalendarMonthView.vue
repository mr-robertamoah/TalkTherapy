<script setup>
import { computed } from 'vue'
import CalendarEvent from '@/Components/CalendarEvent.vue'
import useCalendar from '@/Composables/useCalendar'

const props = defineProps({
  days: { type: Array, required: true },
  events: { type: Array, default: () => [] },
  currentMonth: { type: Date, required: true },
})

defineEmits(['clicked-event'])

const { eventsForDay, format, isSameDay } = useCalendar()

const weeks = computed(() => {
  const chunks = []
  for (let i = 0; i < props.days.length; i += 7) {
    chunks.push(props.days.slice(i, i + 7))
  }
  return chunks
})

function eventsFor(day) {
  return eventsForDay(props.events, day, (event) => event.startTime)
    .sort((a, b) => a.startTime.localeCompare(b.startTime))
}

function isCurrentMonth(day) {
  return day.getMonth() === props.currentMonth.getMonth()
}

const today = new Date()
</script>

<template>
  <div class="space-y-2">
    <div class="grid grid-cols-7 gap-2 text-center text-xs text-gray-500 uppercase">
      <div v-for="day in days.slice(0, 7)" :key="`heading-${day.toISOString()}`">{{ format(day, 'EEE') }}</div>
    </div>

    <div v-for="(week, weekIdx) in weeks" :key="weekIdx" class="grid grid-cols-7 gap-2">
      <div
        v-for="day in week"
        :key="day.toISOString()"
        class="bg-white rounded-lg shadow-sm p-1.5 min-h-[100px]"
        :class="[
          isSameDay(day, today) ? 'ring-2 ring-blue-400' : '',
          isCurrentMonth(day) ? '' : 'opacity-40',
        ]"
      >
        <div class="text-xs font-semibold text-gray-600 text-right pr-1">{{ format(day, 'd') }}</div>

        <div class="space-y-1 mt-1 max-h-[110px] overflow-y-auto">
          <CalendarEvent
            v-for="event in eventsFor(day)"
            :key="event.id"
            :event="event"
            @click="$emit('clicked-event', event)"
          />
        </div>
      </div>
    </div>
  </div>
</template>
