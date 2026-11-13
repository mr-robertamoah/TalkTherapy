<script setup>
// SCRUM-41: near the top of the profile now, in plain words, with a real button.
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    status: {
        type: String,
    },
    email: {
        type: String,
    },
});

const sending = ref(false);

function send() {
    if (sending.value) return;
    sending.value = true;
    router.post(route('verification.send'), {}, {
        preserveScroll: true,
        onFinish: () => { sending.value = false; },
    });
}
</script>

<template>
    <section>
        <h2 class="text-lg font-medium text-gray-900">Email verification</h2>

        <p v-if="!email" class="mt-1 text-sm text-gray-700">
            Your account has no email yet. Add one in "Profile information" below, then verify it here. With a verified
            email you get session reminders and other updates by email, can reset your password if you forget it, and can
            start a therapy.
        </p>

        <template v-else>
            <p class="mt-1 text-sm text-gray-700">
                <span class="font-medium break-all">{{ email }}</span> isn't verified yet. With a verified email you get
                session reminders and other updates by email, can reset your password if you forget it, and can start a
                therapy.
            </p>
            <p class="mt-2 text-sm text-gray-700">
                Press "send the link", then open the email from us and press "Verify Email Address" (the email says how long
                the link works).
            </p>

            <p role="status" class="mt-2 text-sm font-medium" :class="status === 'verification-link-sent' ? 'text-green-700' : 'text-amber-800'">
                {{ status === 'verification-link-sent' ? 'We\'ve sent a new link to your email.' : status === 'verification-throttled' ? 'Please wait a minute before asking for another link.' : '' }}
            </p>

            <PrimaryButton class="mt-4" :disabled="sending" @click="send">
                {{ sending ? 'sending…' : 'send the link' }}
            </PrimaryButton>
        </template>
    </section>
</template>
