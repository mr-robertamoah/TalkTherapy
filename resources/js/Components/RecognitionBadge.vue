<script setup>
// TT-7.11f/SCRUM-476: "Counsellor of the month, <Month Year>" -- on a recognised counsellor's showcase card and their
// profile. The month only: never stars or rank.
import { computed } from 'vue'
import { badgeLabel } from '@/Support/recognitionConsent.js'

const props = defineProps({
    month: { type: String, default: null },
})

const label = computed(() => badgeLabel(props.month))
</script>

<template>
    <!-- Wraps rather than overflowing a narrow showcase card (browser QA, SCRUM-476). -->
    <span class="inline-flex max-w-full items-start gap-1.5 rounded-2xl border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-900">
        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 1.5l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.1l-4.94 2.6.94-5.5-4-3.9 5.53-.8L10 1.5z" />
        </svg>
        {{ label }}
    </span>
</template>
