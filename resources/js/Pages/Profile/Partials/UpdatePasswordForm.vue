<script setup>
import CardActions from '@/Components/CardActions.vue';
import InputError from '@/Components/InputError.vue';
import PasswordHints from '@/Components/PasswordHints.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import usePasswordSet from '@/Composables/usePasswordSet'

const update = ref(false);
const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            update.value = false
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};

// SCRUM-35b: why their password doesn't meet today's rules (from their last sign-in), or null.
const weakReason = computed(() => usePage().props.auth?.user?.passwordWeakReason)
const weakReasonText = computed(() => ({
    short_or_simple: 'It is shorter than the rules ask, or doesn\'t mix letters and numbers.',
    identity: 'It contains your username or email.',
    breached: 'It has turned up in a known data breach, so others may try it.',
}[weakReason.value] ?? ''))

function clickedUpdate() {
    update.value = true
}

// SCRUM-455: an account without a password (made with Google) sets one through the reset email -- the link goes to
// its verified address.
const page = usePage()
const passwordSet = usePasswordSet()
const hasVerifiedEmail = computed(() => !!(page.props.auth?.user?.email && page.props.auth?.user?.emailVerifiedAt))
const linkForm = useForm({})
const linkSent = computed(() => page.props.status === 'password-link-sent')
const linkError = computed(() => page.props.errors?.password_link)

function sendLink() {
    linkForm.post(route('profile.password.link'), { preserveScroll: true })
}
</script>

<template>
    <section v-if="!passwordSet" aria-labelledby="set-password-title">
        <h2 id="set-password-title" class="text-lg font-medium text-gray-900">Set a password</h2>
        <p class="mt-1 text-sm text-gray-600">
            Your account has no password. Set one to change your email, close your account, turn on two-factor or
            download your data.
        </p>
        <p v-if="hasVerifiedEmail" class="mt-1 text-sm text-gray-600">
            We'll email a link to {{ page.props.auth.user.email }}. Open it to choose your password; you'll then sign in again.
        </p>
        <p v-else class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            Add an email address to your account and confirm it first -- the link to set a password is sent there.
        </p>
        <p v-if="linkSent" role="status" class="mt-3 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900">
            We've sent the link -- check your email.
        </p>
        <InputError class="mt-3" :message="linkError" />
        <CardActions v-if="hasVerifiedEmail">
            <PrimaryButton type="button" :disabled="linkForm.processing" @click="sendLink">Email me a link</PrimaryButton>
        </CardActions>
    </section>

    <section v-else>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Update Password</h2>

            <!-- SCRUM-35b: checked when they last signed in; never blocks anything. -->
            <div v-if="weakReason" role="status" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <span class="font-medium">Your password could be stronger.</span>
                {{ weakReasonText }} Please choose a new one{{ update ? ' below' : ': press "Update"' }}.
            </div>

            <p v-if="update" class="mt-1 text-sm text-gray-600">
                Ensure your account is using a long, random password to stay secure.
            </p>
            <p v-else class="mt-1 text-sm text-gray-600">
                Ensure your account is well protected from other persons. Use strong password. You can have your password changed here. Start by clicking update.
            </p>
            <CardActions v-if="!update">
                <PrimaryButton @click="clickedUpdate">Update</PrimaryButton>
            </CardActions>
        </header>

        <form
            v-if="update"
            @submit.prevent="updatePassword" 
            class="mt-8 space-y-6">
            <div>
                <InputLabel for="current_password" value="Current Password" />

                <TextInput
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                />

                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>

            <div>
                <InputLabel for="password" value="New Password" />

                <TextInput
                    id="password"
                    aria-describedby="password-hints"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password" class="mt-2" />
                <PasswordHints />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm Password" />

                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password_confirmation" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                <div
                    @click="() => {
                        if (form.processing) return
                        update = false
                    }" 
                    :disabled="form.processing"
                    class="cursor-pointer ml-2 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >cancel</div>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
