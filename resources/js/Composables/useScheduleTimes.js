import { computed, onBeforeMount, ref, watch } from 'vue'
import { differenceInMinutes } from 'date-fns'
import { addMinutesToInputValue, defaultFormTime, isMinutesBefore, toLocalInputValue } from '@/Composables/countdown'

// SCRUM-379: the start/end time handling the create and update session/discussion modals each had a
// copy of. Values are datetime-local strings (local time); the forms send toISOString() of them.
//
// withDefaults: a create form. Its defaults are set each time it opens (`isOpen` true), not once at
// mount -- the modal stays mounted on the therapy page, so a default set at page load goes stale
// (SCRUM-377). A start the user picked that is still far enough ahead is kept.
//
// The end follows the start: moving the start so the end is under 30 minutes after it moves the end
// too. Editing the END is no longer snapped back (it used to be, which left the input showing the
// typed value while the summary showed the snapped one); timesError() catches a short end on submit.
export const MINUTES_AHEAD = 30
export const MIN_DURATION_MINUTES = 30
const DEFAULT_DURATION_MINUTES = 40

export default function useScheduleTimes({ withDefaults = false, isOpen = () => false } = {}) {
    const startTime = ref('')
    const endTime = ref('')

    function setDefaultTimes() {
        if (startTime.value && isMinutesBefore({ firstTime: startTime.value, minutes: MINUTES_AHEAD })) return

        startTime.value = defaultFormTime(MINUTES_AHEAD)
        endTime.value = addMinutesToInputValue(startTime.value, DEFAULT_DURATION_MINUTES)
    }

    if (withDefaults) {
        onBeforeMount(setDefaultTimes)
        watch(isOpen, (open) => {
            if (open) setDefaultTimes()
        })
    }

    watch(startTime, () => {
        if (!isMinutesBefore({ firstTime: endTime.value, secondTime: startTime.value, minutes: MIN_DURATION_MINUTES })) {
            endTime.value = addMinutesToInputValue(startTime.value, MIN_DURATION_MINUTES)
        }
    })

    // From stored ISO times (an update form opening on an existing record).
    function setTimes(startIso, endIso) {
        startTime.value = startIso ? toLocalInputValue(startIso) : ''
        endTime.value = endIso ? toLocalInputValue(endIso) : ''
    }

    function clearTimes() {
        startTime.value = ''
        endTime.value = ''
    }

    // The message to show, or null when the times are acceptable.
    function timesError() {
        if (!isMinutesBefore({ firstTime: startTime.value, minutes: MINUTES_AHEAD })) {
            return `Start time must be at least ${MINUTES_AHEAD} minutes away from current time. Please increase the start time.`
        }

        if (!isMinutesBefore({ firstTime: endTime.value, secondTime: startTime.value, minutes: MIN_DURATION_MINUTES })) {
            return `End time must be at least ${MIN_DURATION_MINUTES} minutes away from start time. Please increase the end time.`
        }

        return null
    }

    const computedStartTime = computed(() => new Date(startTime.value).toLocaleString())
    const computedEndTime = computed(() => new Date(endTime.value).toLocaleString())
    const computedNow = computed(() => toLocalInputValue(new Date()))
    const computedDuration = computed(() => (endTime.value && startTime.value)
        ? differenceInMinutes(new Date(endTime.value), new Date(startTime.value))
        : '')

    return {
        startTime,
        endTime,
        setTimes,
        clearTimes,
        timesError,
        computedStartTime,
        computedEndTime,
        computedNow,
        computedDuration,
    }
}
