<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Select from '@/Components/Select.vue';
import TextInput from '@/Components/TextInput.vue';
import ProfileInformationDisplay from '@/Components/ProfileInformationDisplay.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { onBeforeMount, ref } from 'vue';

const user = usePage().props.auth.user;

const form = useForm({
    firstName: user.firstName ?? '',
    lastName: user.lastName ?? '',
    otherNames: user.otherNames ?? '',
    email: user.email ?? '',
    dob: user.dob ? getDate(user.dob) : '',
    gender: user.gender ?? '',
    country: user.country ?? '',
});

const countries = ref([
    'Ghana',
    'Nigeria',
    'Uganda',
    'Rwanda'
])

const update = ref(false)

onBeforeMount(() => {
    const c = localStorage.getItem('countries')
    if (c) countries.value = c.split(',')
    getCountries()
})

function getDate(dob) {
    const dateSplit = new Date(dob).toISOString().split('T')

    if (dateSplit.length) return dateSplit[0]

    return ''
}

async function getCountries() {
    await new axios.Axios({
        baseURL: `${import.meta.env.VITE_COUNTRIES_API}`,
    })
    .get('/countries/flag/images')
    .then(res => {

        const retrievedCountries = JSON.parse(res.data).data

       countries.value = retrievedCountries.map(c => c.name)
       localStorage.setItem('countries', [...countries.value].toString())
    })
    .catch(err => {
        console.log(err)
    })
}

function updateProfileInformation() {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => update.value = false
    })
}

function clickedUpdate() {
    update.value = true
}

</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Profile Information</h2>

            <p v-if="update" class="mt-1 text-sm text-gray-600">
                Update your account's profile information and email address.
            </p>

            <template v-else>
                <p class="mt-1 text-sm text-gray-600">
                    These are your personal information currently on the app.
                </p>

                <div class="my-8 mx-auto w-full">
                    <ProfileInformationDisplay
                        class="my-8"
                        label="first name"
                        :text="user?.firstName ?? ''"
                    />
                    <ProfileInformationDisplay
                        class="my-8"
                        label="last name"
                        :text="user?.lastName ?? ''"
                    />
                    <ProfileInformationDisplay
                        class="my-8"
                        label="other names"
                        :text="user?.otherNames ?? ''"
                    />
                    <ProfileInformationDisplay
                        class="my-8"
                        label="email"
                        :capitalize="false"
                        :text="user?.email ?? ''"
                    />
                    <ProfileInformationDisplay
                        class="my-8"
                        label="date of birth"
                        :capitalize="false"
                        :text="user?.dob ? new Date(user.dob).toDateString() : ''"
                    />
                    <ProfileInformationDisplay
                        class="my-8"
                        label="gender"
                        :text="user?.gender ?? ''"
                    />
                    <ProfileInformationDisplay
                        class="my-8"
                        label="country"
                        :text="user?.country ?? ''"
                    />
                </div>
            </template>
            <PrimaryButton v-if="!update" @click="clickedUpdate" class="mr-2 mt-2 float-right">Update</PrimaryButton>
        </header>

        <form
            v-if="update"
            @submit.prevent="updateProfileInformation" class="mt-8 space-y-6">
            <div>
                <InputLabel for="firstName" value="First Name" />

                <TextInput
                    id="firstName"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.firstName"
                    autofocus
                    autocomplete="firstName"
                />

                <InputError class="mt-2" :message="form.errors.firstName" />
            </div>
            
            <div>
                <InputLabel for="lastName" value="Last Name" />

                <TextInput
                    id="lastName"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.lastName"
                    autocomplete="lastName"
                />

                <InputError class="mt-2" :message="form.errors.lastName" />
            </div>

            <div>
                <InputLabel for="otherNames" value="Other Names" />

                <TextInput
                    id="otherNames"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.otherNames"
                    autocomplete="otherNames"
                />

                <InputError class="mt-2" :message="form.errors.otherNames" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    autocomplete="email"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="dob" value="Date of birth" />

                <TextInput
                    id="dob"
                    type="date"
                    class="mt-1 block w-full"
                    v-model="form.dob"
                    autocomplete="dob"
                />

                <InputError class="mt-2" :message="form.errors.dob" />
            </div>

            <div>
                <InputLabel for="gender" value="Gender" />

                <Select
                    id="gender"
                    class="mt-1 block w-full"
                    v-model="form.gender"
                    autocomplete="gender"
                    :options="['male', 'female', {value: 'NON_BINARY', name: 'non-binary'}]"
                    :default-option="'select gender'"
                />

                <InputError class="mt-2" :message="form.errors.gender" />
            </div>

            <div>
                <InputLabel for="country" value="Country" />

                <Select
                    id="country"
                    class="mt-1 block w-full"
                    v-model="form.country"
                    autocomplete="country"
                    :options="countries"
                    :default-option="'select country'"
                />

                <InputError class="mt-2" :message="form.errors.country" />
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
