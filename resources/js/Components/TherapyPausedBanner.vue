<template>
    <!-- SCRUM-288: shown while a therapy is paused because a minor has no active guardian. Plain card
         (no translucency or blur), calm wording, and the same crisis signposting as Pages/Suspended.vue.
         Never says why the guardian is missing or who they were. -->
    <div class="w-full rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm text-sm text-gray-700" role="status">
        <div class="font-semibold text-gray-900 mb-1">This therapy is paused</div>

        <template v-if="viewerPaused">
            <p>
                Your therapy is paused until a parent or guardian is added to your account. You can still
                read your past messages and sessions, but you can't send messages, start sessions or join
                calls for now.
            </p>
            <p class="mt-2">
                It starts again on its own as soon as a guardian is added, or when you turn 18.
                <Link :href="route('profile.show') + '#profile-guardianship-id'" class="font-semibold text-indigo-700 underline">Add a guardian from your profile</Link>.
            </p>
        </template>

        <p v-else>
            This client's participation is paused until their account has a guardian again. You can read the
            history, but messages, sessions and calls are on hold. Upcoming sessions stay scheduled; you can
            still cancel one if you need to.
        </p>

        <p class="mt-2 text-gray-600">
            If you need support urgently, please contact your local emergency services or a crisis line.
        </p>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    // true when the viewer themselves is the paused minor; false when they are the counsellor
    viewerPaused: { type: Boolean, default: false },
})
</script>
