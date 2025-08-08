<script setup>
import Avatar from '@/Components/Avatar.vue';
import CounsellorCreationSteps from '@/Components/CounsellorCreationSteps.vue';
import FormLoader from '@/Components/FormLoader.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ProfileInformationDisplay from '@/Components/ProfileInformationDisplay.vue';
import TextBox from '@/Components/TextBox.vue';
import TextInput from '@/Components/TextInput.vue';
import useAlert from '@/Composables/useAlert';
import useModal from '@/Composables/useModal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watchEffect } from 'vue';
import { computed } from 'vue';
import { watch } from 'vue';
import Alert from '@/Components/Alert.vue';
import Checkbox from '@/Components/Checkbox.vue';
import ActivityBadge from '@/Components/ActivityBadge.vue';
import ProfileEditButton from '@/Components/ProfileEditButton.vue';
import UpdateCounsellorContact from '@/Components/UpdateCounsellorContact.vue';
import UpdateCounsellorInformation from '@/Components/UpdateCounsellorInformation.vue';
import UpdateCounsellorPreferences from '@/Components/UpdateCounsellorPreferences.vue';
import Item from '@/Components/Item.vue';
import UpdateCounsellorImages from '@/Components/UpdateCounsellorImages.vue';
import DangerButton from '@/Components/DangerButton.vue';
import RequirementStatus from '@/Components/RequirementStatus.vue';
import LicenseAuthoritySection from './LicenseAuthoritySection.vue';
import StarBadge from '@/Components/StarBadge.vue';
import StyledLink from '@/Components/StyledLink.vue';
import CreateTherapyButton from '@/Components/CreateTherapyButton.vue';
import TestimonialSection from '@/Components/TestimonialSection.vue';

const { modalData, showModal } = useModal()
const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()

const props = defineProps({
    counsellor: {},
    counsellorCreationStep: {
        type: Number,
    },
    loadedCases: {
        default: []
    },
    loadedLanguages: {
        default: []
    },
    loadedReligions: {
        default: []
    },
    loadedProfessions: {
        default: []
    },
    errors: null
})

const verifyForm = useForm({
    nationalIdFile: null,
    nationalIdNumber: '',
    licenseFile: null,
    licenseNumber: '',
    licensingAuthorityId: null,
})
const deleteForm = useForm({})
const emailVerificationForm = useForm({})

const nationalIdFile = ref(null)
const formDataChanged = ref(false)
const loading = ref(false)
const accountStatuses = ref([
    {name: 'Registered',},
    {name: 'Verified',},
    {name: 'Pending Certification',},
    {name: 'Certified',},
])

watch(
    () => props.errors,
    () => {
        alertCounsellor()
    }
)
watchEffect(
    () => {
        if (props.errors.message)
            setFailedAlertData({
                message: props.errors.message,
            })
    }
)

const isCounsellor = computed(() => {
    return props.counsellor.userId == usePage().props.auth.user?.id
})
const hasContact = computed(() => {
    const contactAvailable = !!(props.counsellor.phone || props.counsellor.email)

    if (
        (props.counsellor.userId == usePage().props.auth.user?.id || props.counsellor.contactVisible) &&
        contactAvailable
    ) return true

    return false
})
const computedClasses = computed(() => {
    return [
        'bg-gray-300 text-gray-700',
        'bg-blue-300 text-blue-700',
        'bg-yellow-300 text-yellow-700',
        'bg-green-300 text-green-700',
    ][props.counsellorCreationStep - 1]
})

function alertCounsellor() {
    const errors = usePage().props.errors
    const keys = Object.keys(errors ?? {})
    let message = ''

    keys.forEach((key) => {
        if (!errors[key]) return
        if (message) message += '\n'

        message += errors[key]
    })

    if (!message) return

    setAlertData({
        show: true,
        message,
        type: 'failed',
    })
}

function deleteCounsellor() {

    deleteForm.delete(route(`counsellor.delete`, { counsellorId: props.counsellor?.id}), {
        onSuccess: () => {
            closeModal()
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}

function changeFile(e) {
    if (e.target.files?.length) {
        verifyForm.nationalIdFile = e.target.files[0]
        nationalIdFile.value.value = ''
    }
    
}

function verifyCounsellor() {
    
    if (
        !(usePage().props.auth.user?.gender)
    ) {
        setAlertData({
            show: true,
            message: 'Your gender is required. Please have it set in your User Profile.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(props.counsellor?.email) && 
        !(props.counsellor?.phone)
    ) {
        setAlertData({
            show: true,
            message: 'Your phone number and email must be set in your Counsellor Profile. We may have to contact you during verification.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(props.counsellor?.emailVerified)
    ) {
        setAlertData({
            show: true,
            message: 'Your email has to be verified. If you have not yet received an email then request one in your Counsellor Profile.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(verifyForm.nationalIdFile) &&
        !(verifyForm.nationalIdNumber)
    ) {
        setAlertData({
            show: true,
            message: 'You are required to provide your National ID number or a file/image of the ID.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(verifyForm.licensingAuthorityId)
    ) {
        setAlertData({
            show: true,
            message: 'You are required to select a Licensing Authority and provide a license associated with them.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(verifyForm.licenseFile) &&
        !(verifyForm.licenseNumber)
    ) {
        setAlertData({
            show: true,
            message: 'You are required to provide a valid ID number or a file/image of the ID//authorization for the Licensing Authority selected.',
            type: 'failed',
        })
        return
    }

    verifyForm.post(route(`counsellor.verify`, { counsellorId: props.counsellor?.id}), {
        onSuccess: () => {
            closeModal()
            setAlertData({
                show: true,
                message: 'Your verification request has successfully been sent.',
                type: 'success',
            })
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}

function requestEmailVerification() {
    emailVerificationForm.post(route(`counsellor.email.verification`, { counsellorId: props.counsellor?.id}), {
        onSuccess: () => {
            closeModal()
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}
 
function closeModal() {
    modalData.value.type = ''
    modalData.value.show = false
}

</script>

<template>
    <Head title="Counsellor" />
    
    <AuthenticatedLayout>
        <div class="pt-4 pb-12">
            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 relative">
                <div v-if="isCounsellor" class="absolute top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <ProfileEditButton
                        v-if="isCounsellor"
                        class="mr-2"
                        @click="() => showModal('update images')"
                    />
                    <div :class="`${computedClasses} p-2 cursor-none rounded font-semibold tracking-wide capitalize`">{{ accountStatuses[counsellorCreationStep - 1].name }}</div>
                </div>
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-b-xl relative pb-20">
                    <div class="relative w-full h-[200px] sm:h-[250px] md:h-[300px]">
                        <img 
                            :src="counsellor?.cover ?? ''" 
                            :alt="'counsellor cover'"
                            v-if="counsellor?.cover"
                            class="w-full h-full object-cover"
                        >
                        <div v-else class="text-sm w-full h-full flex justify-center items-center text-gray-500 bg-gradient-to-br from-blue-50 to-indigo-100">No cover image</div>
                        
                        <!-- Gradient overlay -->
                        <div class="absolute inset-0 bg-gradient-to-t from-black/30 via-transparent to-transparent"></div>
                        
                        <!-- Avatar positioned at bottom of cover -->
                        <div class="absolute -bottom-16 left-4 z-20">
                            <Avatar :src="counsellor?.avatar ?? ''" :size="120" class="ring-4 ring-white shadow-lg" :alt="'counsellor avatar'"/>
                        </div>
                        
                        <!-- Stars badge positioned at bottom right of cover -->
                        <div class="absolute -bottom-12 right-4 sm:right-8 z-20">
                            <StarBadge class="bg-white/95 backdrop-blur-sm rounded-lg p-3 shadow-lg border border-gray-200"
                                :overall="counsellor?.overallStarsCount"
                                :month="counsellor?.currentMonthStarsCount" 
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-24 sm:mt-28 relative">
                <div v-if="isCounsellor" class="absolute z-[1] top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <ProfileEditButton
                        class="mr-2"
                        @click="() => showModal('update main')"
                    />
                </div>
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
                    <div class="p-8 text-center"
                        :class="{'pt-12': isCounsellor}"
                    >
                        <div class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-900 mb-2">{{ counsellor.name }}</div>
                        <div class="w-16 h-1 bg-blue-600 mx-auto mb-4"></div>
                        <div
                            v-if="counsellor.profession"
                            class="text-gray-600 capitalize text-lg font-medium"
                        >{{ counsellor.profession.name }}</div>
                        <div v-else class="text-gray-500 text-sm">No profession added</div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8 relative">
                <div v-if="isCounsellor" class="absolute z-[1] top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <ProfileEditButton
                        class="mr-2"
                        @click="() => showModal('update main')"
                    />
                </div>
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
                    <div class="p-8">
                        <div class="text-xl font-bold text-gray-900 mb-2">About</div>
                        <div class="w-12 h-1 bg-blue-600 mb-4"></div>
                        <div class="text-sm text-gray-600 mb-6">
                            {{ isCounsellor ? 'This is what you have to say about yourself.' : 'Something about this counsellor.' }}
                        </div>

                        <div v-if="counsellor.about" class="bg-gray-50 rounded-lg p-6 border-l-4 border-blue-600">
                            <p class="text-gray-800 leading-relaxed text-center italic">{{ counsellor.about }}</p>
                        </div>
                        <div v-else class="text-center py-8 text-gray-500">
                            <div class="text-4xl mb-2">📝</div>
                            <div>No information added yet</div>
                        </div>
                    </div>
                </div>
            </div>
                
            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8 relative">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <TestimonialSection
                        class="max-w-xl"
                        :addedby="counsellor"
                        :byId="counsellor?.id"
                        :byType="'Counsellor'"
                    />
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8 relative">
                <div v-if="isCounsellor" class="absolute z-[1] top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <ProfileEditButton
                        class="mr-2"
                        @click="() => showModal('update contact')"
                    />
                </div>
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl relative">
                    <div class="p-8">
                        <div class="text-xl font-bold text-gray-900 mb-2">Contact Information</div>
                        <div class="w-12 h-1 bg-blue-600 mb-6"></div>
                        
                        <div v-if="hasContact" class="space-y-6">
                            <div v-if="counsellor.phone" class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg">
                                <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500 font-medium">Phone Number</div>
                                    <div class="text-gray-900 font-semibold">{{ counsellor.phone }}</div>
                                </div>
                            </div>
                            
                            <div v-if="counsellor.email" class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg">
                                <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500 font-medium">Email Address</div>
                                    <div class="text-gray-900 font-semibold">{{ counsellor.email }}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div v-else class="text-center py-8 text-gray-500">
                            <div class="text-4xl mb-2">📞</div>
                            <div>No contact information available</div>
                        </div>

                        <div class="mt-6 flex justify-end" v-if="isCounsellor && !counsellor?.emailVerified">
                            <PrimaryButton @click="requestEmailVerification" class="bg-blue-600 hover:bg-blue-700">Request Email Verification</PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8 relative">
                <div v-if="isCounsellor" class="absolute z-[1] top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <ProfileEditButton
                        class="mr-2"
                        @click="() => showModal('update preferences')"
                    />
                </div>
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl relative">
                    <div class="p-8">
                        <div class="text-xl font-bold text-gray-900 mb-2">Preferences</div>
                        <div class="w-12 h-1 bg-blue-600 mb-4"></div>
                        <div class="text-sm text-gray-600 mb-8">
                            {{ 
                                isCounsellor
                                    ? 'Set preferences to match with potential clients.'
                                    : 'These are the counsellor\'s specializations and preferences.'
                            }}
                        </div>
                        
                        <div class="space-y-8">
                            <div>
                                <div class="flex items-center space-x-2 mb-4">
                                    <div class="w-6 h-6 bg-green-600 rounded-full flex items-center justify-center">
                                        <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="font-semibold text-gray-900">Cases</h3>
                                </div>
                                <div v-if="counsellor.cases?.length" class="flex flex-wrap gap-2">
                                    <Item v-for="item in counsellor.cases" :key="item.id" :item="item" class="bg-green-50 border-green-200" />
                                </div>
                                <div v-else class="text-gray-500 text-sm bg-gray-50 p-4 rounded-lg text-center">
                                    {{ isCounsellor ? 'You have ' : '' }}No cases set
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex items-center space-x-2 mb-4">
                                    <div class="w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center">
                                        <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M7 2a1 1 0 011 1v1h3a1 1 0 110 2H9.578a18.87 18.87 0 01-1.724 4.78c.29.354.596.696.914 1.026a1 1 0 11-1.44 1.389c-.188-.196-.373-.396-.554-.6a19.098 19.098 0 01-3.107 3.567 1 1 0 01-1.334-1.49 17.087 17.087 0 003.13-3.733 18.992 18.992 0 01-1.487-2.494 1 1 0 111.79-.89c.234.47.489.928.764 1.372.417-.934.752-1.913.997-2.927H3a1 1 0 110-2h3V3a1 1 0 011-1zm6 6a1 1 0 01.894.553l2.991 5.982a.869.869 0 01.02.037l.99 1.98a1 1 0 11-1.79.895L15.383 16h-4.764l-.723 1.447a1 1 0 11-1.79-.894l.99-1.98.019-.038 2.99-5.982A1 1 0 0113 8zm-1.382 6h2.764L13 12.236 11.618 14z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="font-semibold text-gray-900">Languages</h3>
                                </div>
                                <div v-if="counsellor.languages?.length" class="flex flex-wrap gap-2">
                                    <Item v-for="item in counsellor.languages" :key="item.id" :item="item" class="bg-blue-50 border-blue-200" />
                                </div>
                                <div v-else class="text-gray-500 text-sm bg-gray-50 p-4 rounded-lg text-center">
                                    {{ isCounsellor ? 'You have ' : '' }}No languages set
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex items-center space-x-2 mb-4">
                                    <div class="w-6 h-6 bg-purple-600 rounded-full flex items-center justify-center">
                                        <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="font-semibold text-gray-900">Religions</h3>
                                </div>
                                <div v-if="counsellor.religions?.length" class="flex flex-wrap gap-2">
                                    <Item v-for="item in counsellor.religions" :key="item.id" :item="item" class="bg-purple-50 border-purple-200" />
                                </div>
                                <div v-else class="text-gray-500 text-sm bg-gray-50 p-4 rounded-lg text-center">
                                    {{ isCounsellor ? 'You have ' : '' }}No religions set
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="capitalize text-lg font-medium text-gray-900">counsellor account status</div>
                        <div
                            class="mt-1 text-sm text-gray-600"
                        >{{
                            isCounsellor 
                                ? 'This shows you the stages you will have to go though to be fully certified counsellor on this platform.' 
                                : `This shows the current stage this counsellor is on becoming certified on this platform.`
                        }}</div>

                        <div class="mt-4 mb-2">
                            <CounsellorCreationSteps
                                :current-step="counsellorCreationStep"
                                :steps="isCounsellor ? [] : accountStatuses"
                            />
                        </div>

                        <div class="mt-4 mb-2 flex items-end p-2 flex-col space-y-4" v-if="isCounsellor">
                            <PrimaryButton v-if="!counsellor.hasPendingCounsellorVerificationRequest && counsellorCreationStep == 1" @click="() => showModal('verify')">request verification</PrimaryButton>
                            <div v-if="counsellor.hasPendingCounsellorVerificationRequest && counsellorCreationStep == 1" class=" text-sm text-gray-600 w-full text-start">You have a pending verification request.</div>
                            <div v-if="counsellorCreationStep == 2" class=" text-sm text-gray-600 w-full text-start">You have been verified and awaiting certification. Request to <Link :href="route('home')" class="font-bold mx-1 cursor-pointer p-1 rounded bg-gray-200">assist</Link> a user needing therapy.</div>
                            <div v-if="counsellorCreationStep >= 3" class=" text-sm text-gray-600 w-full text-start">At least you have one therapy. Have at least one session. Visit Your <Link :href="route('therapies')" class="font-bold mx-1 cursor-pointer p-1 rounded bg-gray-200">therapies</Link> to start a session now.</div>      
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8">
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
                    <div class="p-8">
                        <div class="text-xl font-bold text-gray-900 mb-2">Activities</div>
                        <div class="w-12 h-1 bg-blue-600 mb-4"></div>
                        <div class="text-sm text-gray-600 mb-8">
                            {{ 
                                isCounsellor
                                    ? 'Your activity summary on the platform.'
                                    : 'This counsellor\'s activity summary on the platform.'
                            }}
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-blue-50 p-6 rounded-lg border border-blue-200">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-blue-900">{{ counsellor.paidTherapiesCount || 0 }}</div>
                                        <div class="text-sm text-blue-700 font-medium">Paid Therapies</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-green-50 p-6 rounded-lg border border-green-200">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-green-900">{{ counsellor.freeTherapiesCount || 0 }}</div>
                                        <div class="text-sm text-green-700 font-medium">Free Therapies</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-purple-50 p-6 rounded-lg border border-purple-200">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-purple-600 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zM14.553 7.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-purple-900">{{ counsellor.onlineSessionsHeldCount || 0 }}</div>
                                        <div class="text-sm text-purple-700 font-medium">Online Sessions</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-orange-50 p-6 rounded-lg border border-orange-200">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-orange-600 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-orange-900">{{ counsellor.groupTherapiesCount || 0 }}</div>
                                        <div class="text-sm text-orange-700 font-medium">Group Therapies</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8" v-if="!isCounsellor">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="capitalize text-lg font-medium text-gray-900">Therapy</div>
                        <div class="mt-1 text-sm text-gray-600">
                            Create a therapy that requests the assistance of this counsellor.
                        </div>
                        <div v-if="!$page.props.auth.user" class="mt-1 text-sm text-red-600">
                            You would have to login in order to create a request.
                        </div>

                        <div class="flex justify-end mt-6 ">

                            <CreateTherapyButton :counsellor="counsellor" v-if="$page.props.auth.user"/>
                            <StyledLink v-else :text="'login to create a therapy'" :href="route('login')"/>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-8" v-if="isCounsellor">
                <div class="bg-red-300 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="capitalize text-lg font-medium text-gray-900">Delete</div>
                        <div class="mt-1 text-sm text-red-600">
                            This deletes this counsellor account together with everything that this account has done on this platform.
                        </div>

                        <div class="flex justify-end">

                            <PrimaryButton class="mt-6 bg-red-700 hover:bg-red-500" @click="() => showModal('delete')">delete account</PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-12">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center text-sm">
                        {{ 
                            isCounsellor
                                ? 'Keep helping by "TalkTherapy"ing.'
                                : `Thank you for visiting the profile of ${counsellor.name}`
                        }}
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal
        :show="modalData.show && ['verify', 'delete'].includes(modalData.type)"
        @close="closeModal"
    >
        <div class="p-4 select-none" v-if="modalData.type == 'verify'">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Request Counsellor Verification</div>
                <hr>
            </div>

            <div class="p-2 pb-4 h-[80vh] overflow-hidden overflow-y-auto">
                <CounsellorCreationSteps
                    class="mt-8 p-2"
                    :current-step="2"
                    :light="false"
                />

                <div class="text-gray-600 font-semibold mt-6">Requirements</div>
                <div class="text-sm text-gray-600 mt-1">These requirements have to be met before you can request for a verification</div>
                <div>
                    <FormLoader class="mx-auto" :show="loading" :text="'sending verification request'"/>
                    <form 
                        @submit.prevent="verifyCounsellor"
                    >

                        <div class="p-2 mt-2 mb-4 flex flex-col justify-center items-start">
                            <RequirementStatus
                                :requirement="'gender set'"
                                :info="'set your gender on your user profile.'"
                                :met="!!$page.props.auth.user?.gender"
                                class="mb-4"
                            />
                            <RequirementStatus
                                :requirement="'contact info provided'"
                                :info="'set your email and phone number on your counsellor profile.'"
                                :met="!!counsellor.phone && !!counsellor.email"
                                class="mb-4"
                            />
                            <RequirementStatus
                                :requirement="'email verified'"
                                :info="'visit your mail box and verify email or request verification from counsellor profile.'"
                                :met="!!counsellor.emailVerified"
                                class="mb-4"
                            />
                        </div>
                        
                        <div class="p-2 mt-2 mb-4 flex flex-col justify-center items-start bg-gray-200 rounded">
                            <div class="text-sm text-gray-600 mt-1">National Identification</div>
                            <div class="w-full mt-4 mx-auto max-w-[400px]">
                                <input @change="changeFile" accept="image/*,application/pdf,application/docx" type="file" name="national_id" id="national_id" class="hidden" ref="nationalIdFile">
                                <InputLabel for="nationalIdNumber" value="National Id Number" />

                                <TextInput
                                    id="nationalIdNumber"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="verifyForm.nationalIdNumber"
                                    autofocus
                                />
                                
                                <InputError class="mt-2" :message="verifyForm.errors.nationalIdNumber" />

                                <div class="flex items-center justify-end my-4">
                                    <div v-if="verifyForm.nationalIdFile" class="text-gray-600 text-sm mr-2">{{ verifyForm.nationalIdFile.name }}</div>

                                    <PrimaryButton @click.prevent="() => {
                                        nationalIdFile.click()
                                    }">
                                        {{verifyForm.nationalIdFile ? 'change file' : 'add file'}}
                                    </PrimaryButton>
                                </div>
                            </div>
                        </div>

                        <div class="w-full mt-4 mx-auto max-w-[700px]">
                            <LicenseAuthoritySection
                                :start="modalData.type == 'verify'"
                                class="bg-gray-200 rounded-sm"
                                @on-data="(data) => {
                                    console.log(data)
                                    verifyForm.licensingAuthorityId = data.licensingAuthorityId
                                    verifyForm.licenseNumber = data.number
                                    verifyForm.licenseFile = data.file
                                }"
                                :addedby="{
                                    type: 'Counsellor',
                                    id: counsellor.id
                                }"
                                :errors="{
                                    file: verifyForm.errors.licenseFile,
                                    number: verifyForm.errors.licenseNumber,
                                }"
                            />
                        </div>

                        <div class="flex items-center justify-end mt-4">

                            <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                                send request
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

        </div>
        
        <div class="p-4 select-none" v-if="modalData.type == 'delete'">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-red-800 to-red-500 bg-clip-text text-transparent mb-2"
                >Delete Counsellor Account</div>
                <hr>
            </div>

            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'deleting counsellor account'"/>
                <div class="my-6 w-full text-center text-red-700">
                    Are you sure you want to delete this account?
                </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton @click="() => closeModal()" class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                            cancel
                        </PrimaryButton>
                        <form 
                            @submit.prevent="deleteCounsellor"
                        >

                            <DangerButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                                delete
                            </DangerButton>
                        </form>
                    </div>
            </div>
        </div>
    </Modal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />

    <UpdateCounsellorContact
        :counsellor="counsellor"
        :show="modalData.show && modalData.type == 'update contact'"
        @close-modal="() => {
            closeModal()
        }"
    />

    <UpdateCounsellorInformation
        :counsellor="counsellor"
        :show="modalData.show && modalData.type == 'update main'"
        :professions="loadedProfessions?.data ?? []"
        @close-modal="() => {
            closeModal()
        }"
    />

    <UpdateCounsellorPreferences
        :counsellor="counsellor"
        :show="modalData.show && modalData.type == 'update preferences'"
        :cases="loadedCases?.data ?? []"
        :languages="loadedLanguages?.data ?? []"
        :religions="loadedReligions?.data ?? []"
        @close-modal="() => {
            closeModal()
        }"
    />

    <UpdateCounsellorImages
        :counsellor="counsellor"
        :show="modalData.show && modalData.type == 'update images'"
        @close-modal="() => {
            closeModal()
        }"
    />
    
</template>
