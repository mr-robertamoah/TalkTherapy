<script setup>
// SCRUM-455 (Google sign-in, SCRUM-112): connect a Google account to sign in with, or disconnect it -- each after the
// password. Disconnecting needs one to exist (Google would otherwise be the only way in). Never shows which Google
// account it is: TalkTherapy keeps only Google's id for it.
import { computed, nextTick, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import GoogleSignInButton from '@/Components/GoogleSignInButton.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import TextInput from '@/Components/TextInput.vue'
import SetPasswordPointer from '@/Components/SetPasswordPointer.vue'
import usePasswordSet from '@/Composables/usePasswordSet'

const props = defineProps({
    // { connected, connectedAt, canConnect }
    google: { type: Object, required: true },
})

const page = usePage()
const passwordSet = usePasswordSet()
const error = computed(() => page.props.errors?.google)
const statusText = computed(() => ({
    'google-connected': 'Google is connected. You can now sign in with it.',
    'google-disconnected': 'Google is disconnected.',
}[page.props.status] ?? ''))

const connectedSince = computed(() => props.google.connectedAt
    ? new Date(props.google.connectedAt).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
    : '')

// Connecting or disconnecting: one password form at a time.
const asking = ref(false)
const passwordInput = ref(null)
const form = useForm({ current_password: '' })

function ask() {
    form.reset()
    form.clearErrors()
    asking.value = true
    nextTick(() => passwordInput.value?.focus())
}

function cancel() {
    if (form.processing) return
    asking.value = false
    form.reset()
    form.clearErrors()
}

function submit() {
    const options = {
        preserveScroll: true,
        onError: () => {
            form.reset('current_password')
            nextTick(() => passwordInput.value?.focus())
        },
        onSuccess: () => {
            asking.value = false
            form.reset()
        },
    }

    // Connecting leaves for Google's page (the server answers with its address); disconnecting stays here.
    if (props.google.connected) {
        form.delete(route('profile.google.disconnect'), options)
    } else {
        form.post(route('profile.google.connect'), options)
    }
}
</script>

<template>
    <section aria-labelledby="google-sign-in-title">
        <header>
            <h2 id="google-sign-in-title" class="text-lg font-medium text-gray-900">Google sign-in</h2>
            <p class="mt-1 text-sm text-gray-600">
                Sign in with your Google account as well. We keep only Google's id for that account and when you
                connected it -- never your Google password. Connecting or disconnecting signs you out on your other devices.
            </p>
            <p class="mt-3 text-sm">
                Status:
                <span v-if="google.connected" class="font-medium text-green-700">connected{{ connectedSince ? ` since ${connectedSince}` : '' }}</span>
                <span v-else class="font-medium text-gray-700">not connected</span>
            </p>
        </header>

        <p v-if="statusText" role="status" class="mt-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900">{{ statusText }}</p>
        <p v-if="error" role="alert" class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">{{ error }}</p>

        <!-- Not connected and connecting isn't offered here (switched off, or no connect callback configured). -->
        <p v-if="!google.connected && !google.canConnect" class="mt-4 text-sm text-gray-600">
            Connecting a Google account isn't available right now.
        </p>

        <!-- No password: connected, Google is the only way in; not connected, there's no password to confirm it with. -->
        <template v-else-if="!passwordSet">
            <div v-if="google.connected" class="mt-6">
                <DangerButton type="button" disabled aria-describedby="google-disconnect-why">Disconnect</DangerButton>
            </div>
            <SetPasswordPointer
                id="google-disconnect-why"
                class="mt-3"
                :action="google.connected ? 'disconnect Google -- it\'s your only way to sign in' : 'connect Google'"
            />
        </template>

        <div v-else-if="!asking" class="mt-6">
            <DangerButton v-if="google.connected" type="button" @click="ask">Disconnect</DangerButton>
            <div v-else class="max-w-xs">
                <GoogleSignInButton @click="ask">Connect Google</GoogleSignInButton>
            </div>
        </div>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <p class="text-sm font-medium text-gray-800">
                {{ google.connected ? 'Confirm it\'s you to disconnect Google' : 'Confirm it\'s you, then choose your Google account' }}
            </p>
            <div>
                <InputLabel for="google_password" value="Your password" />
                <TextInput
                    id="google_password"
                    ref="passwordInput"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>
            <div class="flex items-center gap-3">
                <PrimaryButton type="submit" :disabled="form.processing || !form.current_password">
                    {{ google.connected ? 'Disconnect' : 'Continue to Google' }}
                </PrimaryButton>
                <SecondaryButton type="button" :disabled="form.processing" @click="cancel">Cancel</SecondaryButton>
            </div>
        </form>
    </section>
</template>
