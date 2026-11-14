<script setup>
// SCRUM-427: the page behind an email-change link -- confirming a new address, or "This wasn't me". Opening it changes
// nothing (mail scanners open links); the button does it.
import GuestLayout from '@/Layouts/GuestLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import { Head, useForm } from '@inertiajs/vue3'

const props = defineProps({
    mode: { type: String, required: true }, // confirm | stop
    action: { type: String, required: true },
})

const form = useForm({})

function submit() {
    form.post(props.action)
}
</script>

<template>
    <GuestLayout>
        <Head :title="mode === 'stop' ? 'Stop the email change' : 'Confirm your new email'" />

        <template v-if="mode === 'confirm'">
            <h1 class="text-lg font-medium text-gray-900">Confirm your new email address</h1>
            <p class="mt-2 text-sm text-gray-600">
                Use this address for the TalkTherapy account that asked for it. If you didn't expect this, just close
                this page -- nothing changes.
            </p>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <PrimaryButton :disabled="form.processing" @click="submit">Confirm this address</PrimaryButton>
            </div>
        </template>

        <template v-else>
            <h1 class="text-lg font-medium text-gray-900">This wasn't me</h1>
            <p class="mt-2 text-sm text-gray-600">
                Stopping it cancels the change of your account's email and signs your account out everywhere. Then reset
                your password -- someone may know it.
            </p>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <DangerButton :disabled="form.processing" @click="submit">Stop the change</DangerButton>
            </div>
        </template>
    </GuestLayout>
</template>
