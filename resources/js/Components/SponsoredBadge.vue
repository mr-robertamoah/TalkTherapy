<script setup>
// SCRUM-180: an organisation pays for this therapy or group. The server sends `sponsor` to its client only
// (ResolveTherapySponsorAction) -- the organisation may be their employer or school -- so this never decides
// who sees it. Short on a card; the organisation's name on hover and for screen readers.
import { computed } from 'vue'

const props = defineProps({
    sponsor: { type: Object, default: null }, // { organizationName }
})

const label = computed(() => (props.sponsor?.organizationName ? `Paid by ${props.sponsor.organizationName}` : 'Paid by an organization'))
</script>

<template>
    <span
        v-if="sponsor"
        class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 whitespace-nowrap"
        :title="label"
    >
        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M4 4a2 2 0 0 0-2 2v1h16V6a2 2 0 0 0-2-2H4Zm14 5H2v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9ZM4 13a1 1 0 0 1 1-1h1a1 1 0 1 1 0 2H5a1 1 0 0 1-1-1Zm5-1a1 1 0 1 0 0 2h1a1 1 0 1 0 0-2H9Z" clip-rule="evenodd" />
        </svg>
        <span aria-hidden="true">Sponsored</span>
        <span class="sr-only">{{ label }}</span>
    </span>
</template>
