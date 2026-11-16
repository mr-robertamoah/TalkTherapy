<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// SCRUM-454: a new account for a Google account connected to nothing. Nothing from Google is shown or asked about here
// (no name, photo or email) -- whether its email is kept is decided on the server and never said.
const form = useForm({
    username: '',
    dob: '',
});

// The browser's own date (not UTC), as YYYY-MM-DD -- a date of birth can't be in the future (the server checks too).
const now = new Date()
const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-')

const submit = () => {
    form.post(route('auth.google.finish.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Finish setting up" />

        <h1 class="text-lg font-medium text-gray-900">Finish setting up</h1>
        <p class="mt-1 text-sm text-gray-600">
            Choose a username for your new TalkTherapy account. You'll sign in with Google.
        </p>

        <form class="mt-6" @submit.prevent="submit">
            <div>
                <InputLabel for="username" value="Username" />

                <TextInput
                    id="username"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.username"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.username" />
            </div>

            <div class="mt-4">
                <InputLabel for="dob" value="Date of birth (optional)" />

                <TextInput
                    id="dob"
                    type="date"
                    class="mt-1 block w-full"
                    aria-describedby="dob-note"
                    :max="today"
                    v-model="form.dob"
                    autocomplete="bday"
                />

                <p id="dob-note" class="mt-1 text-xs text-gray-500">
                    Some features and safeguards depend on your age; without a date of birth we treat you as under 18.
                </p>

                <InputError class="mt-2" :message="form.errors.dob" />
            </div>

            <div class="mt-6 flex justify-end">
                <PrimaryButton class="text-nowrap" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Create account
                </PrimaryButton>
            </div>
        </form>

        <p class="mt-6 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            Already have a TalkTherapy account?
            <Link
                :href="route('login')"
                class="underline text-gray-700 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
            >Sign in with it instead</Link>
            and connect Google from your profile.
        </p>
    </GuestLayout>
</template>
