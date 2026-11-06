<script setup>
// SCRUM-30: where a counsellor's platform verification stands, wherever a counsellor is shown.
// `verification` comes from the server (App\Support\CounsellorVerification::projection) -- this never
// works anything out from dates. Neutral wording: "not verified" is a fact to know, not an accusation.
import { computed } from 'vue'

const props = defineProps({
    verification: { type: Object, default: null },
    size: { type: String, default: 'sm' }, // 'sm' | 'md'
})

const STATES = {
    verified: {
        label: 'Verified',
        title: 'TalkTherapy has checked this counsellor\'s ID and licence.',
        classes: 'bg-green-50 text-green-800 border-green-200',
    },
    unverified: {
        label: 'Not verified yet',
        title: 'TalkTherapy hasn\'t checked this counsellor\'s ID and licence yet.',
        classes: 'bg-gray-50 text-gray-700 border-gray-300',
    },
    lapsed: {
        label: 'Verification ended',
        title: 'This counsellor\'s verification has ended and hasn\'t been renewed yet.',
        classes: 'bg-amber-50 text-amber-800 border-amber-200',
    },
    // Only the counsellor themselves and administrators are ever sent this state.
    revoked: {
        label: 'Verification revoked',
        title: 'An administrator revoked this verification.',
        classes: 'bg-red-50 text-red-800 border-red-200',
    },
}

const state = computed(() => STATES[props.verification?.state] ?? null)
</script>

<template>
    <span
        v-if="state"
        class="inline-flex items-center gap-1 rounded-full border font-medium whitespace-nowrap"
        :class="[state.classes, size === 'md' ? 'px-3 py-1 text-sm' : 'px-2 py-0.5 text-xs']"
        :title="state.title"
    >
        <svg v-if="verification.state === 'verified'" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
        </svg>
        <svg v-else class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
        </svg>
        <span>{{ state.label }}</span>
    </span>
</template>
