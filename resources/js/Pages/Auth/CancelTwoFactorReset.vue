<script setup>
// SCRUM-424: the "cancel this reset" link from the email. Opening the page changes nothing (mail scanners open links);
// the button cancels. No sign-in needed.
import GuestLayout from '@/Layouts/GuestLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

const props = defineProps({
    state: { type: String, required: true }, // pending | cancelled | applied
    dueAt: { type: String, required: true },
    action: { type: String, required: true },
})

const form = useForm({})

// In the reader's own time zone (the server sends the moment).
const dueLabel = new Date(props.dueAt).toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' })

function cancel() {
    form.post(props.action, { preserveScroll: true })
}
</script>

<template>
    <GuestLayout>
        <Head title="Cancel two-factor reset" />

        <h1 class="text-lg font-medium text-gray-900">Two-factor sign-in reset</h1>

        <template v-if="state === 'pending'">
            <p class="mt-2 text-sm text-gray-600">
                The TalkTherapy team has scheduled a reset of two-factor sign-in on this account, to take effect on
                <span class="font-medium text-gray-900">{{ dueLabel }}</span>. If you didn't ask for it, cancel it now --
                then change your password and contact support.
            </p>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <PrimaryButton :disabled="form.processing" @click="cancel">Cancel this reset</PrimaryButton>
            </div>
        </template>

        <div v-else-if="state === 'cancelled'" role="status" class="mt-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900">
            This reset is cancelled. Two-factor sign-in stays on, as it was.
        </div>

        <div v-else role="status" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This reset has already taken effect. If you didn't ask for it, change your password and contact support
            straight away.
        </div>

        <p class="mt-6 text-sm text-gray-600">
            <Link :href="route('login')" class="underline hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">Go to sign in</Link>
        </p>
    </GuestLayout>
</template>
