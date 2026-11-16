<script setup>
// SCRUM-455 (Google sign-in, SCRUM-112): in place of a password field, for an account without a password (made with
// Google) -- it has none to type, so this points to "Set a password" on the profile. `action` finishes the sentence.
import { Link } from '@inertiajs/vue3'

defineProps({
    action: { type: String, required: true },
})
</script>

<template>
    <p class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
        Your account has no password.
        <Link :href="`${route('profile.show')}#profile-password-id`" class="font-medium text-indigo-700 underline hover:text-indigo-900">Set a password</Link>
        first to {{ action }}.
    </p>
</template>
