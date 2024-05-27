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
                time: 5000
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
        time: 4000
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
            time: 4000
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
            time: 4000
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
            time: 4000
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
            time: 4000
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
            time: 4000
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
            time: 4000
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
                time: 4000
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-b-lg">
                    <div class="p-1 text-gray-900 text-center bg-gray-300 w-full h-[200px] sm:h-[250px] md:h-[300px]">
                        <img 
                            :src="counsellor?.cover ?? ''" 
                            :alt="'counsellor cover'"
                            v-if="counsellor?.cover"
                            class="w-full h-full object-cover rounded-b-lg"
                        >
                        <div v-else class="text-sm w-full h-full flex justify-center items-center text-gray-600 bg-white rounded-b-lg">no cover image</div>
                    </div>
                    <Avatar :src="counsellor?.avatar ?? ''" class="absolute z-10 -bottom-[60px] sm:-bottom-[75px] left-2" :alt="'counsellor avatar'"/>
                    <StarBadge class="absolute z-10 -bottom-[50px] right-1 sm:right-7"
                        :overall="counsellor?.overallStarsCount"
                        :month="counsellor?.currentMonthStarsCount" 
                    />
                    <!-- TODO get actual stars -->
                </div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[75%] lg:w-[60%] mx-auto sm:px-6 lg:px-8 mt-24 sm:mt-28 relative">
                <div v-if="isCounsellor" class="absolute z-[1] top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <ProfileEditButton
                        class="mr-2"
                        @click="() => showModal('update main')"
                    />
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 w-fit text-center mx-auto"
                        :class="{'pt-10': isCounsellor}"
                    >
                        <div class="text-lg sm:text-2xl md:text-3xl border-b-2 pb-2 border-gray-300 tracking-widest w-fit capitalize font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">{{ counsellor.name }}</div>
                        <div
                            v-if="counsellor.profession"
                            class="text-gray-600 capitalize text-sm tracking-wide mt-2 font-semibold"
                        >{{ counsellor.profession.name }}</div>
                        <div v-else class="text-gray-600 lowercase text-xs mt-2">no profession added</div>
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-lg font-medium text-gray-900">About</div>
                        <div
                            v-if="isCounsellor"
                            class="mt-1 text-sm text-gray-600"
                        >This is what you have to say about yourself.</div>
                        <div
                            v-else
                            class="mt-1 text-sm text-gray-600"
                        >Something about this counsellor.</div>

                        <div
                            v-if="counsellor.about"
                            class="mt-6 tracking-wide text-sm text-stone-800 font-semibold w-[90%] mx-auto text-center"
                        >{{ counsellor.about }}</div>
                        <div
                            v-else
                            class="mt-6 text-sm text-gray-600 text-center w-full"
                        >nothing yet</div>
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg relative">
                    <div class="p-6">
                        <div class="text-lg font-medium text-gray-900">Contact Information</div>
                        <div
                            class="text-gray-600"
                            v-if="hasContact"
                        >
                            <ProfileInformationDisplay
                                class="my-8"
                                label="phone number"
                                v-if="counsellor.phone"
                                :text="counsellor.phone ?? ''"
                            />
                            <ProfileInformationDisplay
                                class="my-8"
                                label="email"
                                v-if="counsellor.email"
                                :text="counsellor.email ?? ''"
                                :capitalize="false"
                            />
                        </div>
                        <div 
                            v-else
                            class="mt-1 text-sm text-gray-600"
                        >No email or phone number has been made available</div>

                        <div class="mt-4 flex justify-end" v-if="isCounsellor && !counsellor?.emailVerified">
                            <PrimaryButton @click="requestEmailVerification">request email verification</PrimaryButton>
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg relative">
                    <div class="p-6">
                        <div class="text-lg font-medium text-gray-900">Preferences</div>
                        <div class="mt-1 text-sm text-gray-600">
                            {{ 
                                isCounsellor
                                    ? 'Get to set preferences with which to match the preferences of potential clients.'
                                    : 'These are the cases, languages and religions of set by counsellor as his/her preferences. Note that this platform is not religious, hence you can talk to anyone outside of your religion.'
                            }}
                        </div>
                        
                        <div class="mt-6 mb-4">
                            <div class="capitilize font-medium tracking-wide mb-2">Cases</div>
                            <div
                                class="flex overflow-hidden overflow-x-auto justify-start items-center p-2"
                                v-if="counsellor.cases?.length"
                            >
                                <Item
                                    v-for="item in counsellor.cases"
                                    :key="item.id"
                                    :item="item"
                                    class="mr-3"
                                />
                            </div>
                            <div
                                v-else
                                class="mt-1 text-sm text-gray-600 text-center"
                            >{{ isCounsellor ? 'you have ' : '' }}no cases set</div>
                        </div>
                        
                        <div class="mt-6 mb-4">
                            <div class="capitilize font-medium tracking-wide mb-2">Languages</div>
                            <div
                                class="flex overflow-hidden overflow-x-auto justify-start items-center p-2"
                                v-if="counsellor.languages?.length"
                            >
                                <Item
                                    v-for="item in counsellor.languages"
                                    :key="item.id"
                                    :item="item"
                                    class="mr-3"
                                />
                            </div>
                            <div
                                v-else
                                class="mt-1 text-sm text-gray-600 text-center"
                            >{{ isCounsellor ? 'you have ' : '' }}no languages set</div>
                        </div>
                        
                        <div class="mt-6 mb-4">
                            <div class="capitilize font-medium tracking-wide mb-2">Religions</div>
                            <div
                                class="flex overflow-hidden overflow-x-auto justify-start items-center p-2"
                                v-if="counsellor.religions?.length"
                            >
                                <Item
                                    v-for="item in counsellor.religions"
                                    :key="item.id"
                                    :item="item"
                                    class="mr-3"
                                />
                            </div>
                            <div
                                v-else
                                class="mt-1 text-sm text-gray-600 text-center"
                            >{{ isCounsellor ? 'you have ' : '' }}no religions set</div>
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="capitalize text-lg font-medium text-gray-900">Activities</div>
                        <div class="mt-1 text-sm text-gray-600">
                            {{ 
                                isCounsellor
                                    ? 'These are information about what and how you have been doing on this platform.'
                                    : 'These are information about what and how this counsellor has been doing on this application'
                            }}
                        </div>

                        <div class="mt-4 flex flex-col items-start justify-start">
                            <ActivityBadge
                                :name="'paid therapies'"
                                :value="counsellor.paidTherapiesCount"
                            />
                            <ActivityBadge
                                :name="'free therapies'"
                                :value="counsellor.freeTherapiesCount"
                                class="mt-4 ml-0 sm:ml-8"
                            />
                            <ActivityBadge
                                :name="'online sessions held'"
                                :value="counsellor.onlineSessionsHeldCount"
                                class="mt-4"
                            />
                            <ActivityBadge
                                :name="'in-person sessions held'"
                                :value="counsellor.inPersonSessionsCount"
                                class="mt-4 ml-0 sm:ml-8"
                            />
                            <ActivityBadge
                                :name="'group therapies'"
                                :value="counsellor.groupTherapiesCount"
                                class="mt-4"
                            />
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
