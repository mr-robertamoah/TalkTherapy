<script setup>
// SCRUM-35: the password rules, shown before anyone types -- from the policy the server checks (the shared
// passwordPolicy prop), so the two can't drift.
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

defineProps({
    id: { type: String, default: 'password-hints' },
})

const policy = computed(() => usePage().props.passwordPolicy)
</script>

<template>
    <ul v-if="policy" :id="id" class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-gray-600">
        <li>At least {{ policy.min }} characters, with letters and numbers. A few words together work well.</li>
        <li>Not your username or email.</li>
        <li v-if="policy.uncompromised">Not one that has turned up in a known data breach.</li>
    </ul>
</template>
