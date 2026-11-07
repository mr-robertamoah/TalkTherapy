<script setup>
// SCRUM-393: the small mark a card shows when it matches the viewer's preferences (SCRUM-113 --
// only the viewer sees it, and never why). It explains itself on hover and on keyboard focus, and
// the Preferences page shows the same mark with what it means.
defineProps({
    // 'legend' drops the tooltip and focus stop -- for the legend on the Preferences page.
    legend: { type: Boolean, default: false },
    // Where the tooltip opens; 'above' where the card clips what's below the mark.
    placement: { type: String, default: 'below' },
})

const label = 'Matches your preferences'
</script>

<template>
    <!-- One mark; as the legend it is hidden from screen readers and takes no focus or tooltip. -->
    <span
        class="group relative inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-green-300 bg-green-50 text-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600"
        :role="legend ? undefined : 'img'"
        :aria-label="legend ? undefined : label"
        :aria-hidden="legend ? 'true' : undefined"
        :tabindex="legend ? undefined : 0"
    >
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M15.98 1.804a1 1 0 0 0-1.96 0l-.24 1.192a1 1 0 0 1-.784.785l-1.192.238a1 1 0 0 0 0 1.962l1.192.238a1 1 0 0 1 .785.785l.238 1.192a1 1 0 0 0 1.962 0l.238-1.192a1 1 0 0 1 .785-.785l1.192-.238a1 1 0 0 0 0-1.962l-1.192-.238a1 1 0 0 1-.785-.785l-.238-1.192ZM6.949 5.684a1 1 0 0 0-1.898 0l-.683 2.051a1 1 0 0 1-.633.633l-2.051.683a1 1 0 0 0 0 1.898l2.051.684a1 1 0 0 1 .633.632l.683 2.051a1 1 0 0 0 1.898 0l.683-2.051a1 1 0 0 1 .633-.633l2.051-.683a1 1 0 0 0 0-1.898l-2.051-.683a1 1 0 0 1-.633-.633L6.95 5.684ZM13.949 13.684a1 1 0 0 0-1.898 0l-.184.551a1 1 0 0 1-.632.633l-.551.183a1 1 0 0 0 0 1.898l.551.183a1 1 0 0 1 .633.633l.183.551a1 1 0 0 0 1.898 0l.184-.551a1 1 0 0 1 .632-.633l.551-.183a1 1 0 0 0 0-1.898l-.551-.184a1 1 0 0 1-.633-.632l-.183-.551Z" /></svg>
        <span
            v-if="!legend"
            :class="placement === 'above' ? 'bottom-full left-0 mb-1' : 'top-full right-0 mt-1'"
            class="pointer-events-none absolute z-20 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block group-focus:block"
            aria-hidden="true"
        >{{ label }}</span>
    </span>
</template>
