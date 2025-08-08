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

        <div class="py-12 bg-gray-50 min-h-screen">
            <div class="sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 my-4 flex justify-end">
                <HelpButton
                    title="get help on your profile page"
                    :page="PAGES.profile"
                    class="mr-4"
                />
            </div>
            
            <!-- Hero Section -->
            <div class="sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mb-10">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl shadow-xl p-8 text-white">
                    <div class="text-center">
                        <div class="text-4xl font-bold mb-2">Welcome Back!</div>
                        <div class="text-xl opacity-90">@{{ $page.props.auth.user.username }}</div>
                        <div class="w-16 h-1 bg-white/50 mx-auto mt-4"></div>
                    </div>
                </div>
            </div>
            
            <div class="sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 space-y-8">
                
                <div id="profile-therapies-id" class="relative"></div>
                <div class="p-8 bg-white shadow-xl border border-gray-100 rounded-xl">
                    <TherapiesSection class="max-w-xl" />
                </div>
                
                <div id="profile-guardianship-id" class="relative"></div>
                <div class="p-8 bg-white shadow-xl border border-gray-100 rounded-xl">
                    <GuardianshipSection class="max-w-xl" />
                </div>
                
                <div id="profile-testimonial-id" class="relative"></div>
                <div class="p-8 bg-white shadow-xl border border-gray-100 rounded-xl">
                    <TestimonialSection
                        class="max-w-xl"
                        :addedby="$page.props.auth.user"
                        :byId="$page.props.auth.user?.id"
                        :byType="'User'"
                    />
                </div>
                
                <div id="profile-info-id" class="relative"></div>
                <div class="p-8 bg-white shadow-xl border border-gray-100 rounded-xl">
                    <UpdateProfileInformationForm class="max-w-xl" />
                </div>
                
                <div
                    class="p-8 bg-yellow-50 border border-yellow-200 shadow-xl rounded-xl"
                    v-if="mustVerifyEmail && !$page.props.auth.user?.emailVerifiedAt"
                >
                    <VerifyEmailSection
                        :status="status"
                        :email="$page.props.auth.user?.email"
                        class="max-w-xl"
                    />
                </div>

                <div id="profile-pref-id" class="relative"></div>
                <div class="p-8 bg-gradient-to-r from-purple-600 to-indigo-600 shadow-xl rounded-xl text-white">
                    <section class="max-w-xl">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M5 4a1 1 0 00-2 0v7.268a2 2 0 000 3.464V16a1 1 0 102 0v-1.268a2 2 0 000-3.464V4zM11 4a1 1 0 10-2 0v1.268a2 2 0 000 3.464V16a1 1 0 102 0V8.732a2 2 0 000-3.464V4zM16 3a1 1 0 011 1v7.268a2 2 0 010 3.464V16a1 1 0 11-2 0v-1.268a2 2 0 010-3.464V4a1 1 0 011-1z"></path>
                                </svg>
                            </div>
                            <h2 class="text-xl font-bold">Set Preferences</h2>
                        </div>
                        <p class="text-white/90 mb-6 leading-relaxed">
                            Customize your preferences to match with the right counsellors for your needs.
                        </p>
                        <Link :href="route('preferences')" class="inline-flex items-center px-6 py-3 bg-white text-purple-600 font-semibold rounded-lg hover:bg-gray-100 transition-colors">
                            Manage Preferences
                            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </Link>
                    </section>
                </div>

                <div id="profile-password-id" class="relative"></div>
                <div class="p-8 bg-white shadow-xl border border-gray-100 rounded-xl">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div id="profile-counsellor-id" class="relative"></div>
                <div class="p-8 bg-gradient-to-r from-green-600 to-teal-600 shadow-xl rounded-xl text-white">
                    <BecomeCounsellorForm
                        :counsellor-creation-step="currentStep"
                        @change-step="changeStep"
                        class="max-w-xl"
                    />
                </div>

                <div id="profile-account-id" class="relative"></div>
                <div class="p-8 bg-white shadow-xl border border-gray-100 rounded-xl">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
