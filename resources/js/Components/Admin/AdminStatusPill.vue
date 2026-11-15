<script setup>
// SCRUM-430: a small solid status label for admin lists.
import { computed } from 'vue'

const props = defineProps({
    label: { type: String, required: true },
    tone: { type: String, default: 'gray' }, // gray | green | amber | red | blue | indigo
})

const tones = {
    gray: 'bg-gray-100 text-gray-700 border-gray-200',
    green: 'bg-green-50 text-green-800 border-green-200',
    amber: 'bg-amber-50 text-amber-900 border-amber-200',
    red: 'bg-red-50 text-red-800 border-red-200',
    blue: 'bg-blue-50 text-blue-800 border-blue-200',
    indigo: 'bg-indigo-50 text-indigo-800 border-indigo-200',
}

const classes = computed(() => tones[props.tone] ?? tones.gray)
</script>

<template>
    <span class="inline-flex items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium" :class="classes">{{ label }}</span>
</template>
