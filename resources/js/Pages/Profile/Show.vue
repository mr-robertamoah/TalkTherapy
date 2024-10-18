<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import VerifyEmailSection from './Partials/VerifyEmailSection.vue';
import BecomeCounsellorForm from './Partials/BecomeCounsellorForm.vue';
import TherapiesSection from './Partials/TherapiesSection.vue';
import TestimonialSection from '@/Components/TestimonialSection.vue';
import GuardianshipSection from '@/Components/GuardianshipSection.vue';
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import HelpButton from '@/Components/HelpButton.vue';
import useGuidedTours from '@/Composables/useGuidedTours';


const { PAGES } = useGuidedTours()
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
            <div class="sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 my-4 flex justify-end">
                <HelpButton
                    title="get help on your profile page"
                    :page="PAGES.profile"
                    class="mr-4"
                />
            </div>
            <div class="sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 space-y-10">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="flex justify-center items-end font-bold text-2xl">
                        <div class="mr-4">Welcome</div>
                        <div class="text-lg sm:text-2xl md:text-3xl border-b border-slate-600 tracking-widest w-fit bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent underline">{{ $page.props.auth.user.username }}</div>
                    </div>
                </div>
                
                <div id="profile-therapies-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <TherapiesSection
                        class="max-w-xl"
                    />
                </div>
                
                                <div id="profile-guardianship-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <GuardianshipSection
                        class="max-w-xl"
                    />
                </div>
                
                                <div id="profile-testimonial-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <TestimonialSection
                        class="max-w-xl"
                        :addedby="$page.props.auth.user"
                        :byId="$page.props.auth.user?.id"
                        :byType="'User'"
                    />
                </div>
                
                                <div id="profile-info-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <UpdateProfileInformationForm
                        class="max-w-xl"
                    />
                </div>
                
                <div
                    class="p-4 sm:p-8 bg-white shadow sm:rounded-lg"
                    v-if="mustVerifyEmail && !$page.props.auth.user?.emailVerifiedAt"
                >
                    <VerifyEmailSection
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div id="profile-pref-id" class="relative"></div>
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

                <div id="profile-password-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div id="profile-counsellor-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-slate-500 shadow sm:rounded-lg">
                    <BecomeCounsellorForm
                        :counsellor-creation-step="currentStep"
                        @change-step="changeStep"
                        class="max-w-xl"
                    />
                </div>

                <div id="profile-account-id" class="relative"></div>
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
