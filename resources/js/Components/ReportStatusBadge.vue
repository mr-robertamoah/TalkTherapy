<script setup>
import { computed } from 'vue'

// SCRUM-340: a report's review status, the same everywhere it's shown.
const props = defineProps({
    status: { type: String, default: 'open' },
})

const classes = {
    open: 'bg-amber-50 text-amber-800 border-amber-200',
    under_review: 'bg-blue-50 text-blue-800 border-blue-200',
    resolved: 'bg-green-50 text-green-800 border-green-200',
    dismissed: 'bg-gray-100 text-gray-700 border-gray-200',
}

const label = computed(() => (props.status ?? 'open').replace('_', ' '))
</script>

<template>
    <span
        class="inline-block px-2 py-0.5 rounded-md border text-xs font-medium capitalize whitespace-nowrap"
        :class="classes[status ?? 'open'] ?? classes.open"
    >{{ label }}</span>
</template>
