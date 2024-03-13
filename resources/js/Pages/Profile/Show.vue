<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import BecomeCounsellorForm from './Partials/BecomeCounsellorForm.vue';
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    counsellorCreationStep: {
        type: Number,
    },
});

const step = ref(0)

const currentStep = computed(() => {
    return step.value > props.counsellorCreationStep
        ? step.value : props.counsellorCreationStep
})

function changeStep(value) {
    console.log('step value', value)
    step.value = Number(value)
}
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Profile</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="flex justify-center items-end font-bold text-2xl">
                        <div class="mr-4">Welcome</div>
                        <div class="text-3xl border-b border-slate-600 tracking-widest w-fit bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent underline">{{ $page.props.auth.user.username }}</div>
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-slate-500 shadow sm:rounded-lg">
                    <BecomeCounsellorForm
                        :counsellor-creation-step="currentStep"
                        @change-step="changeStep"
                        class="max-w-xl"
                    />
                </div>
                
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="p-4 sm:p-8 bg-slate-500 shadow sm:rounded-lg">
                    <section class="max-w-xl">
                        <header>
                            <h2 class="text-lg font-medium text-gray-300">Set Preferences</h2>

                            <p class="mt-1 text-sm text-gray-100 text-justify">
                                There are things you may prefer, regarding the kind counsellors you may want to interact with, and we give you the opportunity to set them.
                            </p>

                            <Link :href="route('preferences')" class="p-2 float-right my-2 mr-2 px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                preferences
                            </Link>
                        </header>
                    </section>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
