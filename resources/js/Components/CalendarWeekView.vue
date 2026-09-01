<script setup>
import { computed } from 'vue'
import CalendarEvent from '@/Components/CalendarEvent.vue'
import useCalendar from '@/Composables/useCalendar'

const props = defineProps({
  days: { type: Array, required: true },
  events: { type: Array, default: () => [] },
})

defineEmits(['clicked-event'])

const { eventsForDay, format, isSameDay } = useCalendar()

const columns = computed(() => props.days.map((day) => ({
  day,
  events: eventsForDay(props.events, day, (event) => event.startTime)
    .sort((a, b) => a.startTime.localeCompare(b.startTime)),
})))

const today = new Date()
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-7 gap-2">
    <div
      v-for="column in columns"
      :key="column.day.toISOString()"
      class="bg-white rounded-lg shadow-sm p-2 min-h-[160px]"
      :class="{ 'ring-2 ring-blue-400': isSameDay(column.day, today) }"
    >
      <div class="text-center mb-2">
        <div class="text-xs text-gray-500 uppercase">{{ format(column.day, 'EEE') }}</div>
        <div class="text-sm font-semibold text-gray-700">{{ format(column.day, 'd MMM') }}</div>
      </div>

      <div class="space-y-1">
        <CalendarEvent
          v-for="event in column.events"
          :key="event.id"
          :event="event"
          @click="$emit('clicked-event', event)"
        />
        <div v-if="!column.events.length" class="text-center text-xs text-gray-400 mt-4">no sessions</div>
      </div>
    </div>
  </div>
</template>
