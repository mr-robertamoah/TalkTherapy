<script setup>
// The user's TODO (TT-12.2a): read as a plain list; Edit (bottom left) turns the card into the form. SCRUM-422: changing
// the email (adding, replacing or clearing it) asks for the current password, and the previous verified address is told.
import CardActions from '@/Components/CardActions.vue';
import CountrySelect from '@/Components/CountrySelect.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Select from '@/Components/Select.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { format, subYears } from 'date-fns';

defineProps({
    dobChangePendingApproval: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
// Always the latest saved values (a one-off copy went stale after a save, and Cancel brought old edits back).
const user = computed(() => page.props.auth.user ?? {});

// A date of birth is a calendar day: read its YYYY-MM-DD as is. (new Date(...) on the server's value shifted it a day
// in timezones behind UTC.)
function dayOf(value) {
    const day = String(value ?? '').slice(0, 10);

    return /^\d{4}-\d{2}-\d{2}$/.test(day) ? day : '';
}

function showDay(day) {
    if (!day) return '';
    const [year, month, date] = day.split('-').map(Number);

    return new Date(year, month - 1, date).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
}

function savedValues() {
    return {
        firstName: user.value.firstName ?? '',
        lastName: user.value.lastName ?? '',
        otherNames: user.value.otherNames ?? '',
        email: user.value.email ?? '',
        dob: dayOf(user.value.dob),
        gender: user.value.gender ?? '',
        country: user.value.country ?? '',
        current_password: '',
    };
}

const form = useForm(savedValues());

const editing = ref(false);
const saved = ref(false);
const editButton = ref(null);
const firstField = ref(null);

// Stored as GenderEnum values (MALE, FEMALE, NON_BINARY).
const GENDERS = { MALE: 'Male', FEMALE: 'Female', NON_BINARY: 'Non-binary' };

const details = computed(() => [
    { label: 'First name', value: user.value.firstName },
    { label: 'Last name', value: user.value.lastName },
    { label: 'Other names', value: user.value.otherNames },
    { label: 'Email', value: user.value.email, email: true },
    { label: 'Date of birth', value: showDay(dayOf(user.value.dob)) },
    { label: 'Gender', value: GENDERS[String(user.value.gender ?? '').toUpperCase()] ?? user.value.gender },
    { label: 'Country', value: user.value.country },
]);

// The same test the server makes (ProfileUpdateRequest::changesEmail): the address as typed, against the saved one.
const changingEmail = computed(() => (form.email.trim() || null) !== (user.value.email || null));

// In local time, like the day it's compared with (the server's rule is the real limit).
const maxDob = computed(() => format(subYears(new Date(), 10), 'yyyy-MM-dd'));

function edit() {
    form.defaults(savedValues());
    form.reset();
    form.clearErrors();
    saved.value = false;
    editing.value = true;
    nextTick(() => firstField.value?.focus());
}

function cancel() {
    if (form.processing) return;
    form.reset();
    form.clearErrors();
    editing.value = false;
    nextTick(() => editButton.value?.$el?.focus?.());
}

function save() {
    form.transform((data) => (changingEmail.value ? data : { ...data, current_password: undefined }))
        .patch(route('profile.update'), {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
                saved.value = true;
                form.defaults(savedValues());
                form.reset();
                nextTick(() => editButton.value?.$el?.focus?.());
            },
            // A refusal keeps what they typed -- only the password is cleared.
            onError: () => form.reset('current_password'),
        });
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Profile Information</h2>
            <p class="mt-1 text-sm text-gray-600">
                {{ editing ? 'Update your name, email and other details.' : 'Your personal details on the app.' }}
            </p>
        </header>

        <template v-if="!editing">
            <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-[10rem,1fr] sm:gap-y-3">
                <template v-for="detail in details" :key="detail.label">
                    <dt class="pt-2 font-medium text-gray-500 sm:pt-0">{{ detail.label }}</dt>
                    <dd class="break-words text-gray-900">
                        <template v-if="detail.value">
                            {{ detail.value }}
                            <span
                                v-if="detail.email"
                                class="ml-2 inline-block rounded-full border px-2 py-0.5 text-xs font-medium"
                                :class="user.emailVerifiedAt ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-900'"
                            >{{ user.emailVerifiedAt ? 'Verified' : 'Not verified' }}</span>
                        </template>
                        <template v-else><span class="text-gray-400" aria-hidden="true">—</span><span class="sr-only">Not set</span></template>
                    </dd>
                </template>
            </dl>

            <div
                v-if="dobChangePendingApproval"
                role="status"
                class="mt-6 rounded-md border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800"
            >Your date-of-birth change has been submitted for your guardian's (or an admin's) approval and will take effect once approved. Everything else you changed has already been saved.</div>

            <CardActions>
                <PrimaryButton ref="editButton" @click="edit">Edit</PrimaryButton>
                <p v-if="saved" role="status" class="text-sm text-gray-600">Saved.</p>
            </CardActions>
        </template>

        <form v-else class="mt-6 space-y-6" @submit.prevent="save">
            <div>
                <InputLabel for="firstName" value="First name" />
                <TextInput id="firstName" ref="firstField" v-model="form.firstName" type="text" class="mt-1 block w-full" autocomplete="given-name" />
                <InputError class="mt-2" :message="form.errors.firstName" />
            </div>

            <div>
                <InputLabel for="lastName" value="Last name" />
                <TextInput id="lastName" v-model="form.lastName" type="text" class="mt-1 block w-full" autocomplete="family-name" />
                <InputError class="mt-2" :message="form.errors.lastName" />
            </div>

            <div>
                <InputLabel for="otherNames" value="Other names" />
                <TextInput id="otherNames" v-model="form.otherNames" type="text" class="mt-1 block w-full" autocomplete="additional-name" />
                <InputError class="mt-2" :message="form.errors.otherNames" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" autocomplete="email" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="changingEmail" class="rounded-md border border-gray-200 bg-gray-50 p-4">
                <InputLabel for="profile_current_password" value="Your current password" />
                <p class="mt-1 text-xs text-gray-600">
                    Needed to change your email. You'll need to verify the new address, and we'll let your current one know.
                </p>
                <TextInput
                    id="profile_current_password"
                    v-model="form.current_password"
                    type="password"
                    class="mt-2 block w-full"
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div>
                <InputLabel for="dob" value="Date of birth" />
                <TextInput id="dob" v-model="form.dob" type="date" class="mt-1 block w-full" :max="maxDob" autocomplete="bday" />
                <InputError class="mt-2" :message="form.errors.dob" />
            </div>

            <div>
                <InputLabel for="gender" value="Gender" />
                <Select
                    id="gender"
                    v-model="form.gender"
                    class="mt-1 block w-full"
                    :options="['male', 'female', { value: 'NON_BINARY', name: 'non-binary' }]"
                    :default-option="'select gender'"
                />
                <InputError class="mt-2" :message="form.errors.gender" />
            </div>

            <div>
                <InputLabel for="country" value="Country" />
                <CountrySelect v-model="form.country" input-id="country" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.country" />
            </div>

            <InputError :message="form.errors.profile" />

            <CardActions>
                <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
                <SecondaryButton type="button" :disabled="form.processing" @click="cancel">Cancel</SecondaryButton>
            </CardActions>
        </form>
    </section>
</template>
