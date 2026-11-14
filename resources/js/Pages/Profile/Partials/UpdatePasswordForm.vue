<script setup>
import CardActions from '@/Components/CardActions.vue';
import InputError from '@/Components/InputError.vue';
import PasswordHints from '@/Components/PasswordHints.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
</script>

<template>
    <section>
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
