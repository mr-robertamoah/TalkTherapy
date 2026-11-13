<script setup>
// SCRUM-34b: the second step of signing in -- the 6-digit code from the authenticator app, or a recovery code.
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

const useRecovery = ref(false)
const input = ref(null)
const form = useForm({ code: '' })

function toggle() {
    useRecovery.value = !useRecovery.value
    form.reset('code')
    form.clearErrors()
    nextTick(() => input.value?.focus())
}

function submit() {
    form.post(route('two_factor.challenge.store'), {
        onFinish: () => form.reset('code'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Two-factor sign-in" />

        <h1 class="text-lg font-medium text-gray-900">Two-factor sign-in</h1>
        <p class="mt-1 text-sm text-gray-600">
            <template v-if="!useRecovery">Enter the 6-digit code from your authenticator app.</template>
            <template v-else>Enter one of the recovery codes you saved when you turned on two-factor sign-in. Each works once.</template>
        </p>

        <form class="mt-6" @submit.prevent="submit">
            <InputLabel for="code" :value="useRecovery ? 'Recovery code' : '6-digit code'" />
            <TextInput
                id="code"
                ref="input"
                v-model="form.code"
                type="text"
                class="mt-1 block w-full"
                :inputmode="useRecovery ? 'text' : 'numeric'"
                :autocomplete="useRecovery ? 'off' : 'one-time-code'"
                :maxlength="useRecovery ? 20 : 7"
                required
                autofocus
            />
            <InputError class="mt-2" :message="form.errors.code" />

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <button
                    type="button"
                    class="text-sm text-gray-600 underline hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    @click="toggle"
                >
                    {{ useRecovery ? 'Use a code from my app' : 'Lost your phone? Use a recovery code' }}
                </button>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing || !form.code">Continue</PrimaryButton>
            </div>
        </form>

        <p class="mt-6 text-sm text-gray-600">
            <Link
                :href="route('login')"
                class="underline hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
            >Back to sign in</Link>
        </p>
    </GuestLayout>
</template>
