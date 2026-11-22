<script setup>
// TT-7.11f/SCRUM-476: a Counsellor of the month on Home -- RecognisedCounsellorResource (name, photo, profession, up to
// 3 cases, the month). Solid card, no star counts.
import { Link } from '@inertiajs/vue3'
import Avatar from './Avatar.vue'
import RecognitionBadge from './RecognitionBadge.vue'

defineProps({
    counsellor: { type: Object, required: true },
})
</script>

<template>
    <div class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3">
            <Avatar :size="56" :src="counsellor.avatar ?? ''" :alt="counsellor.name" class="shrink-0" />
            <div class="min-w-0">
                <div class="truncate font-semibold text-gray-900 capitalize">{{ counsellor.name }}</div>
                <div class="truncate text-sm text-gray-600 capitalize">{{ counsellor.profession ?? 'Counsellor' }}</div>
            </div>
        </div>

        <RecognitionBadge :month="counsellor.month" class="mt-3 self-start" />

        <ul v-if="counsellor.cases?.length" class="mt-3 flex flex-wrap gap-1.5" aria-label="Helps with">
            <li
                v-for="name in counsellor.cases"
                :key="name"
                class="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-gray-700 capitalize"
            >{{ name }}</li>
        </ul>

        <!-- Plain Link, not StyledLink (see StarredCounsellorComponent.vue). -->
        <Link
            :href="route('counsellor.show', { counsellorId: counsellor.id })"
            class="mt-4 inline-block self-start rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-50"
        >View profile</Link>
    </div>
</template>
