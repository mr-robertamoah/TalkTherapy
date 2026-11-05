<script setup>
import { computed, ref, watch } from 'vue'
import InputLabel from '@/Components/InputLabel.vue'

// SCRUM-381: the terms of a safeguarding exception -- a reason and an end date -- shared by the
// counsellor's request modal, the super administrator's approval (RequestBadge) and the admin page.
// The end is a preset (7, 14 or 30 days from now) or a date; `endsAt` is emitted as an ISO (UTC)
// string. The server checks the limits again: more than an hour away and at most `maxDays` days.
// SCRUM-384: `maxDays` is SafeguardingException::MAX_DAYS, passed down by each page (never a copy here).

const reason = defineModel('reason', { type: String, default: '' })
const endsAt = defineModel('endsAt', { type: String, default: '' })

const props = defineProps({
    idPrefix: { type: String, default: 'safeguarding' },
    reasonLabel: { type: String, default: 'Reason' },
    endLabel: { type: String, default: 'Ends' },
    // An ISO date the form starts from (e.g. what the counsellor proposed).
    initialEndsAt: { type: String, default: '' },
    maxDays: { type: Number, required: true },
})

// The usual choices, up to the limit (which is always offered).
const presets = computed(() => [...new Set([7, 14, props.maxDays].filter((days) => days <= props.maxDays))])
const choice = ref(String(presets.value.includes(14) ? 14 : presets.value[0])) // a preset's days, or 'date'
const date = ref('')

function toDateInput(value) {
    const d = new Date(value)
    const pad = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const minDate = computed(() => toDateInput(Date.now() + 24 * 3600 * 1000))
const maxDate = computed(() => toDateInput(Date.now() + props.maxDays * 24 * 3600 * 1000))

// A prefilled date (e.g. a proposal from days ago) is kept within today's limits (YYYY-MM-DD strings
// compare in date order).
if (props.initialEndsAt) {
    const initial = toDateInput(props.initialEndsAt)
    choice.value = 'date'
    date.value = initial < minDate.value ? minDate.value : (initial > maxDate.value ? maxDate.value : initial)
}

function compute() {
    if (choice.value === 'date') {
        if (!date.value) return ''
        // The end of the chosen day, local time, kept within maxDays of now.
        const end = new Date(`${date.value}T23:59:00`)
        const latest = Date.now() + props.maxDays * 24 * 3600 * 1000 - 60 * 1000
        return new Date(Math.min(end.getTime(), latest)).toISOString()
    }

    return new Date(Date.now() + Number(choice.value) * 24 * 3600 * 1000 - 60 * 1000).toISOString()
}

watch([choice, date], () => { endsAt.value = compute() }, { immediate: true })

const endsAtLabel = computed(() => endsAt.value ? new Date(endsAt.value).toLocaleString() : '')
</script>

<template>
    <div class="space-y-3 text-sm">
        <div>
            <InputLabel :for="`${idPrefix}-reason`" :value="reasonLabel" />
            <textarea
                :id="`${idPrefix}-reason`"
                v-model="reason"
                rows="3"
                maxlength="2000"
                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
            ></textarea>
        </div>

        <div>
            <InputLabel :value="endLabel" />
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <label v-for="days in presets" :key="days" class="inline-flex items-center gap-1 px-2 py-1 border border-gray-200 rounded-md bg-white cursor-pointer">
                    <input type="radio" :value="String(days)" v-model="choice" class="text-indigo-600 focus:ring-indigo-500" />
                    <span>{{ days }} days</span>
                </label>
                <label class="inline-flex items-center gap-1 px-2 py-1 border border-gray-200 rounded-md bg-white cursor-pointer">
                    <input type="radio" value="date" v-model="choice" class="text-indigo-600 focus:ring-indigo-500" />
                    <span>a date</span>
                </label>
                <input
                    v-if="choice === 'date'"
                    :id="`${idPrefix}-date`"
                    v-model="date"
                    type="date"
                    :min="minDate"
                    :max="maxDate"
                    class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                />
            </div>
            <div class="mt-1 text-xs text-gray-500">{{ endsAtLabel ? `Until ${endsAtLabel}.` : 'Pick an end date.' }} At most {{ maxDays }} days.</div>
        </div>
    </div>
</template>
