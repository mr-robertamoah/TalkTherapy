<script setup>
import { ref, computed, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import CalendarWeekView from '@/Components/CalendarWeekView.vue'
import CalendarMonthView from '@/Components/CalendarMonthView.vue'
import useCalendar from '@/Composables/useCalendar'
import useAlert from '@/Composables/useAlert'
import Alert from '@/Components/Alert.vue'

// SCRUM-213/TT-2.6b: fetches its own range-scoped data client-side (no session data is passed as
// an Inertia prop) -- matches this feature's own "never the counsellor's entire session history
// in one payload" requirement. Drill-through only: clicking an event navigates to the underlying
// Therapy/GroupTherapy page, where the real session actions (accept/reject/counter-offer/end/etc.)
// already live -- no session-mutation action exists on this page.
const {
  weekRange, monthRange, daysInRange, toApiDate, addWeeks, addMonths, format,
} = useCalendar()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()

const viewMode = ref('week')
const anchorDate = ref(new Date())
const sessions = ref([])
const loading = ref(false)
const typeFilter = ref('all')
const timeFilter = ref('all')

const viewItems = [
  { id: 'week', name: 'week' },
  { id: 'month', name: 'month' },
]

const currentRange = computed(() => (
  viewMode.value === 'week' ? weekRange(anchorDate.value) : monthRange(anchorDate.value)
))

const days = computed(() => daysInRange(currentRange.value.start, currentRange.value.end))

const rangeLabel = computed(() => {
  if (viewMode.value === 'month') return format(anchorDate.value, 'MMMM yyyy')
  return `${format(currentRange.value.start, 'd MMM')} – ${format(currentRange.value.end, 'd MMM yyyy')}`
})

const filteredSessions = computed(() => {
  return sessions.value.filter((session) => {
    if (typeFilter.value !== 'all' && session.forType !== typeFilter.value) return false

    if (timeFilter.value !== 'all') {
      const isPast = new Date(session.endTime) < new Date()
      if (timeFilter.value === 'past' && !isPast) return false
      if (timeFilter.value === 'upcoming' && isPast) return false
    }

    return true
  })
})

async function fetchSessions() {
  loading.value = true

  await axios.get(route('api.sessions.calendar', {
    startDate: toApiDate(currentRange.value.start),
    endDate: toApiDate(currentRange.value.end),
  }))
    .then((res) => {
      sessions.value = res.data.sessions
    })
    .catch((err) => {
      console.log(err)
      setFailedAlertData({
        message: err.response?.data?.message ?? 'Could not load your calendar. Please try again shortly.',
      })
    })

  loading.value = false
}

watch(currentRange, fetchSessions, { immediate: true })

function goToPrevious() {
  anchorDate.value = viewMode.value === 'week' ? addWeeks(anchorDate.value, -1) : addMonths(anchorDate.value, -1)
}

function goToNext() {
  anchorDate.value = viewMode.value === 'week' ? addWeeks(anchorDate.value, 1) : addMonths(anchorDate.value, 1)
}

function goToToday() {
  anchorDate.value = new Date()
}

function clickedEvent(session) {
  if (session.forType === 'group') {
    router.get(route('group.therapies.get', { groupTherapyId: session.for.id }))
    return
  }

  router.get(route('therapies.get', { therapyId: session.for.id }))
}
</script>

<template>
  <Head title="My Calendar" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">My Calendar</h2>
    </template>

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-6 space-y-4">
      <div class="bg-white shadow-sm rounded-lg p-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <SecondaryButton @click="goToPrevious">&larr;</SecondaryButton>
          <SecondaryButton @click="goToToday">today</SecondaryButton>
          <SecondaryButton @click="goToNext">&rarr;</SecondaryButton>
          <div class="text-gray-700 font-semibold ml-2">{{ rangeLabel }}</div>
        </div>

        <div class="flex items-center gap-2">
          <div class="flex rounded-lg overflow-hidden border border-gray-200">
            <button
              v-for="item in viewItems"
              :key="item.id"
              @click="viewMode = item.id"
              class="py-1.5 px-3 text-sm capitalize transition-colors duration-150"
              :class="viewMode === item.id ? 'bg-gray-700 text-white' : 'text-gray-600 hover:bg-gray-100'"
            >{{ item.name }}</button>
          </div>

          <select v-model="typeFilter" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
            <option value="all">all sessions</option>
            <option value="individual">individual</option>
            <option value="group">group</option>
          </select>
          <select v-model="timeFilter" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
            <option value="all">upcoming &amp; past</option>
            <option value="upcoming">upcoming</option>
            <option value="past">past</option>
          </select>
        </div>
      </div>

      <div v-if="loading" class="text-center text-sm text-gray-500 py-8">loading your calendar...</div>

      <template v-else>
        <div v-if="!filteredSessions.length" class="bg-white shadow-sm rounded-lg p-3 text-center text-sm text-gray-500">
          no sessions in this range
        </div>

        <CalendarWeekView
          v-if="viewMode === 'week'"
          :days="days"
          :events="filteredSessions"
          @clicked-event="clickedEvent"
        />
        <CalendarMonthView
          v-else
          :days="days"
          :events="filteredSessions"
          :current-month="anchorDate"
          @clicked-event="clickedEvent"
        />
      </template>
    </div>

    <Alert
      :show="alertData.show"
      :type="alertData.type"
      :message="alertData.message"
      :time="alertData.time"
      @close="clearAlertData"
    />
  </AuthenticatedLayout>
</template>
