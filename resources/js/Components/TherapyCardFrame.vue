<script setup>
// SCRUM-393: the shared frame of the therapy and group cards. The kind shows as colour (a blue edge
// and a one-person icon for an individual therapy, an orange edge and a group icon for a group) with
// its name kept for screen readers and on hover, instead of a badge; a preferences match is a small
// mark (PreferenceMatchMark). Everything else is one quiet line of facts, the description, and the
// card's action.
import PreferenceMatchMark from './PreferenceMatchMark.vue'

defineProps({
    kind: { type: String, required: true }, // 'individual' | 'group'
    name: { type: String, default: '' },
    description: { type: String, default: '' },
    facts: { type: Array, default: () => [] }, // short strings, shown as "a · b · c"
    matchesPreferences: { type: Boolean, default: false },
})

const kinds = {
    individual: { label: 'Individual therapy', edge: 'border-l-blue-600', icon: 'bg-blue-50 text-blue-700' },
    group: { label: 'Group therapy', edge: 'border-l-orange-500', icon: 'bg-orange-50 text-orange-700' },
}
</script>

<template>
    <article class="flex h-full min-w-[280px] flex-col rounded-lg border border-l-4 border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md" :class="kinds[kind].edge">
        <div class="flex items-start gap-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="kinds[kind].icon" :title="kinds[kind].label">
                <svg v-if="kind === 'group'" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.97 5.97 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z" />
                </svg>
                <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <span class="sr-only">{{ kinds[kind].label }}:</span>
                <h3 class="line-clamp-2 break-words text-base font-semibold leading-snug text-gray-900" :title="name">{{ name }}</h3>
                <p v-if="facts.length" class="mt-0.5 text-xs text-gray-600">{{ facts.filter(Boolean).join(' · ') }}</p>
            </div>
            <!-- SCRUM-113: only the viewer sees this, and never why. -->
            <PreferenceMatchMark v-if="matchesPreferences" />
        </div>

        <p class="mt-3 line-clamp-2 text-sm text-gray-600">{{ description || 'No description yet.' }}</p>

        <div class="mt-auto pt-4">
            <slot />
        </div>
    </article>
</template>
