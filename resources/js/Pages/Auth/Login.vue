<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    // SCRUM-453: "Continue with Google" -- only when it's switched on and configured.
    googleSignIn: {
        type: Boolean,
        default: false,
    },
});

// SCRUM-453: why a Google sign-in didn't go ahead -- always our own neutral words, never Google's.
const page = usePage()
const googleError = computed(() => page.props.errors?.google)

const form = useForm({
    username: '',
    email: '',
    password: '',
    remember: false,
});

const useUsername = ref(true)

// SCRUM-423: sent here because this browser's session ended (Support/sessionEnded.js). Says nothing of why.
const sessionEnded = new URLSearchParams(window.location.search).get('session') === 'ended'

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div v-if="status" class="mb-4 font-medium text-sm text-green-600">
            {{ status }}
        </div>
        <div v-else-if="sessionEnded" role="status" class="mb-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            Your session ended. Please sign in again.
        </div>

        <div v-if="googleError" role="alert" class="mb-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            {{ googleError }}
        </div>

        <template v-if="googleSignIn">
            <!-- A full page load, not an Inertia visit: it leaves for Google's sign-in page. -->
            <a
                :href="route('auth.google.redirect')"
                class="flex w-full items-center justify-center gap-3 rounded-md border border-[#747775] bg-white px-4 py-2.5 text-sm font-medium text-[#1F1F1F] hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
                </svg>
                <span>Continue with Google</span>
            </a>

            <div class="my-5 flex items-center gap-3" role="separator" aria-label="or">
                <div class="h-px flex-1 bg-gray-200"></div>
                <span class="text-xs uppercase tracking-wide text-gray-500">or</span>
                <div class="h-px flex-1 bg-gray-200"></div>
            </div>
        </template>

        <form @submit.prevent="submit">
            <div v-if="useUsername">
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

            <div v-if="!useUsername">
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="block mt-4">
                <label class="flex items-center">
                    <Checkbox name="use username" v-model:checked="useUsername" />
                    <span class="ms-2 text-sm text-gray-600">Login with username</span>
                </label>
            </div>

            <div class="block mt-4">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-gray-600">Remember me</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                <Link
                    :href="route('register')"
                    class="underline text-sm mr-2 text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    Want to register?
                </Link>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    Forgot your password?
                </Link>

                <PrimaryButton class="ms-4 text-nowrap" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
