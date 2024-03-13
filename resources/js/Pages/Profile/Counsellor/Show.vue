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
import ProfileReligionSection from '../Partials/ProfileReligionSection.vue';
import { onBeforeMount } from 'vue';
import ProfileLanguageSection from '../Partials/ProfileLanguageSection.vue';
import ProfileCaseSection from '../Partials/ProfileCaseSection.vue';
import ProfileProfessionSection from '../Partials/ProfileProfessionSection.vue';
import { watch } from 'vue';
import Alert from '@/Components/Alert.vue';

const { modalData, showModal } = useModal()
const { alertData, clearAlertData, setAlertData } = useAlert()

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

const updateForm = useForm({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
    professionId: [],
    name: '',
    about: '',
    email: '',
    phone: '',
    avatar: null,
    cover: null,
    deleteAvatar: false, // implement
    deleteCover: false,
})
const verifyForm = useForm({
    
})

const data = ref({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
    profession: null,
    cases: [],
    languages: [],
    religions: [],
    professions: [],
})
const formDataChanged = ref(false)
const backendPhone = ref('')
const avatarUrl = ref('')
const coverUrl = ref('')
const phoneData = ref(null)
const avatar = ref(null)
const cover = ref(null)
const code = ref('')
const phoneNumber = ref('')
const loading = ref(false)
const accountStatuses = ref([
    {name: 'Registered',},
    {name: 'Verified',},
    {name: 'Pending Certification',},
    {name: 'Certified',},
])

onBeforeMount(() => {
    setData()
})

watch(
    () => phoneNumber.value,
    () => {
        if (updateForm.errors.phone)
            updateForm.errors.phone = ''
        setBackEndPhoneNumber()

        updateForm.clearErrors('phone')
    }
)
watch(
    () => code.value,
    () => {
        setBackEndPhoneNumber()

        updateForm.clearErrors('phone')
    }
)
watch(
    () => avatar.value?.files,
    () => {
        if (avatar.value?.files) updateForm.avatar = avatar.value.files[0]
        else updateForm.avatar = null

        updateForm.clearErrors('avatar')
    }
)
watch(
    () => cover.value,
    () => {
        if (cover.value?.files) updateForm.cover = cover.value.files[0]
        else updateForm.cover = null

        updateForm.clearErrors('cover')
    }
)
watch(
    () => props.errors,
    () => {
        alertCounsellor()
    }
)
watchEffect(() => {
    formDataChanged.value = false
    if (avatar.value)
        return formDataChanged.value = true
    
    if (cover.value)
        return formDataChanged.value = true

    if (updateForm.about && updateForm.about !== props.counsellor.about) {
        updateForm.clearErrors('about')
        return formDataChanged.value = true
    }
    
    if (updateForm.name && updateForm.name !== props.counsellor.name) {
        updateForm.clearErrors('name')
        return formDataChanged.value = true
    }
    
    if (updateForm.email && updateForm.email !== props.counsellor.email) {
        updateForm.clearErrors('email')
        return formDataChanged.value = true
    }
    
    if (updateForm.avatar)
        return formDataChanged.value = true

    if (updateForm.cover)
        return formDataChanged.value = true
    
    if (backendPhone.value && backendPhone.value !== props.counsellor.phone) {
        updateForm.clearErrors('phone')
        return formDataChanged.value = true
    }
    
    if (data.profession?.id !== props.counsellor.profession?.id) {
        updateForm.clearErrors('professionId')
        return formDataChanged.value = true
    }
    
    if (containSameIds(data.value.selectedCases.map((a) => a.id), props.counsellor.cases.map((a) => a.id))) {
        updateForm.clearErrors('selectedCases')
        return formDataChanged.value = true
    }
    
    if (containSameIds(data.value.selectedLanguages.map((a) => a.id), props.counsellor.languages.map((a) => a.id))) {
        updateForm.clearErrors('selectedLanguages')
        return formDataChanged.value = true
    }
    
    if (containSameIds(data.value.selectedReligions.map((a) => a.id), props.counsellor.religions.map((a) => a.id))) {
        updateForm.clearErrors('selectedReligions')
        return formDataChanged.value = true
    }
})

const computedAvatarUrl = computed(() => {
    return updateForm.avatar ? URL.createObjectURL(updateForm.avatar) : avatarUrl.value
})
const computedCoverUrl = computed(() => {
    return updateForm.cover ? URL.createObjectURL(updateForm.cover) : coverUrl.value
})
const isCounsellor = computed(() => {
    return props.counsellor.userId == usePage().props.auth.user?.id
})
const hasContact = computed(() => {
    return !!(props.counsellor.phone || props.counsellor.email)
})
const computedClasses = computed(() => {
    return [
        'bg-gray-300 text-gray-700',
        'bg-blue-300 text-blue-700',
        'bg-yellow-300 text-yellow-700',
        'bg-green-300 text-green-700',
    ][props.counsellorCreationStep - 1]
})

function setData() {
    phoneData.value = constructPhoneNumberForFrontEnd(props.counsellor.phone)
    data.value.cases = [...props.loadedCases.data]
    data.value.languages = [...props.loadedLanguages.data]
    data.value.religions = [...props.loadedReligions.data]
    data.value.professions = [...props.loadedProfessions.data]

    if (props.counsellor?.cases?.length)
        data.value.selectedCases = [...props.counsellor.cases]
    if (props.counsellor?.languages?.length)
        data.value.selectedLanguages = [...props.counsellor.languages]
    if (props.counsellor?.religions?.length)
        data.value.selectedReligions = [...props.counsellor.religions]
    if (props.counsellor?.profession)
        data.value.profession = {...props.counsellor.professions}
    if (props.counsellor?.phone) {
        phoneNumber.value = phoneData.phone
        code.value = phoneData.code
    }
    if (props.counsellor?.name)
        updateForm.name = props.counsellor.name
    if (props.counsellor?.about)
        updateForm.about = props.counsellor.about
    if (props.counsellor?.email)
        updateForm.email = props.counsellor.email
    if (props.counsellor?.avatar)
        avatarUrl.value = props.counsellor.avatar
    if (props.counsellor?.cover)
        coverUrl.value = props.counsellor.cover
}

function deleteAvatar() {
    if (avatarUrl.value) {
        avatarUrl.value = ''
        updateForm.deleteAvatar = true
        return
    }

    avatar.value = null
    avatarUrl.value = props.counsellor.avatar
}

function constructPhoneNumberForFrontEnd(phone) {
    if (!phone?.length) return {code: '', phone: ''}

    if (phone.length == 10)
        return {code: '', phone}
    
    let idx = phone.length - 9
    return {
        code: phone.splice(0, idx),
        phone: phone.splice(idx,)
    }
}
 
function updateCounsellor() {
    updateForm.selectedCases = data.value.selectedCases.map(c => c.id)
    updateForm.selectedLanguages = data.value.selectedLanguages.map(l => l.id)
    updateForm.selectedReligions = data.value.selectedReligions.map(r => r.id)
    if (data.value.profession)
        updateForm.professionId = data.value.profession.id

    if (phoneNumber.value.length > 9 && code.value) {
        setAlertData({
            show: true,
            type: 'failed',
            message: 'Leave out the 0 in front of phone number since you have the code set.'
        })
        return
    }

    if (phoneNumber.value && phoneNumber.value.length < 9) {
        setAlertData({
            show: true,
            type: 'failed',
            message: 'Phone number should be at least 9 digits.'
        })
        return
    }

    updateForm.phone = backendPhone.value

    if (thereIsNoData()) {
        setAlertData({
            show: true,
            type: 'failed',
            message: "Nothing was provided to update your profile."
        })
        return
    }

    loading.value = true

    updateForm.post(route(`counsellor.update`, { counsellorId: props.counsellor?.id}), {
        onSuccess: () => {
            updateForm.reset(
                'name', 'about', 'professionId', 'selectedCases', 'selectedLanguages', 'selectedReligions',
                'email', 'phone', 'avatar', 'cover', 'deleteAvatar', 'deleteCover'
            )
            closeModal()
        },
    })
    loading.value = false
}

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

function thereIsNoData() {
    if (
        updateForm.name || updateForm.about || updateForm.email || updateForm.phone || updateForm.professionId ||
        updateForm.avatar || updateForm.cover || updateForm.selectedLanguages.length || updateForm.selectedReligions.length ||
        updateForm.selectedCases.length
    ) return false

    return true
}

function containSameIds(frontArray, backArray) {
    if (frontArray.length !== backArray.length) return false

    let same = true

    frontArray.forEach((frontId) => {
        if (!backArray.includes(frontId))
            same = false
    })
    return same
}

function setBackEndPhoneNumber() {
    backendPhone.value = `${code.value}${phoneNumber.value}`
}

function clickedEdit() {
    showModal('update')
}

function verifyCounsellor() {
    
}

function clickedChangeFile(type) {
    if (type == 'avatar' && avatar.value)
        return avatar.value.click()

    cover.value.click()
}

function changeAvatar(e) {
    if (e.target.files?.length)
        updateForm.avatar = e.target.files[0]
}

function changeCover(e) {
    if (e.target.files?.length)
        updateForm.cover = e.target.files[0]
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
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 relative">
                <div v-if="isCounsellor" class="absolute top-0 right-2 sm:right-8 flex justify-end items-center p-2">
                    <PrimaryButton
                        v-if="isCounsellor"
                        class="mr-2"
                        @click="clickedEdit"
                    >edit</PrimaryButton>
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
                    <div class="w-[100px] h-[100px] bg-white absolute z-10 -bottom-[50px] right-1 sm:right-7 shadow-md border-t-2 border-gray-300 flex justify-center items-center flex-col">
                        <div class="flex w-full text-xs">
                            <div class="w-[50%] text-center" title="current month">M</div>
                            <div class="w-1 h-full bg-slate-600"></div>
                            <div class="w-[50%] text-center" title="overall">O</div>
                        </div>
                        <div>stars</div>
                        <div class="flex w-full text-xs">
                            <div class="w-[50%] text-center" title="current month">1</div>
                            <div class="w-1 h-full bg-slate-600"></div>
                            <div class="w-[50%] text-center" title="overall">4</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-24 sm:mt-28">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 w-fit text-center mx-auto">
                        <div class="text-3xl border-b-2 pb-2 border-gray-300 tracking-widest w-fit capitalize font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">{{ counsellor.name }}</div>
                        <div
                            v-if="counsellor.profession"
                            class="text-gray-600 capitalize text-sm font-medium tracking-wide mt-2"
                        >{{ counsellor.profession.name }}</div>
                        <div v-else class="text-gray-600 lowercase text-xs mt-2">no profession added</div>
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
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
                            />
                        </div>
                        <div 
                            v-else
                            class="mt-1 text-sm text-gray-600"
                        >No email or phone number has been made available</div>
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-8">
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
                            <PrimaryButton v-if="counsellorCreationStep == 1" @click="() => showModal('verify')">start verification</PrimaryButton>
                            <div v-if="counsellorCreationStep == 2" class=" text-sm text-gray-600 text-right">You have been verified and awaiting certification. Request to <Link :href="route('home')" class="font-bold mx-1 cursor-pointer p-1 rounded bg-gray-200">assist</Link> a user needing therapy.</div>
                            <div v-if="counsellorCreationStep == 3" class=" text-sm text-gray-600 text-right">At least you have one therapy. Have at least one session. Visit Your <Link :href="route('therapies')" class="font-bold mx-1 cursor-pointer p-1 rounded bg-gray-200">therapies</Link> to start a session now.</div>      
                        </div>
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-8">
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
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-8">
                <!-- TODO its for other users -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="capitalize text-lg font-medium text-gray-900">Therapy</div>
                        <div class="mt-1 text-sm text-gray-600">
                            Create a therapy that requests the assistance of this counsellor.
                        </div>

                        <div class="flex justify-end">

                            <PrimaryButton class="mt-6" @click="() => showModal('therapy')">create therapy</PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-12">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 text-center">
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
        :show="modalData.show"
        @close="closeModal"
    >
        <div class="p-4" v-if="modalData.type == 'verify'">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Verify Counsellor</div>
                <hr>
            </div>

            <CounsellorCreationSteps
                class="mt-8"
                :current-step="2"
                :light="false"
            />

            <div>
                <FormLoader :show="loading" :text="'sending verification request'"/>
                <form 
                    @submit.prevent="verifyCounsellor"
                >

                    <div>
                        requirements checker and redirect to update modal
                        profession
                        language
                        cases
                        religion
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                            send request
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
        <div class="p-4" v-else-if="modalData.type == 'update'">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Counsellor Account</div>
                <hr>
            </div>

            <FormLoader class="top-14" :show="loading" :text="'updating counsellor account...'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form 
                    @submit.prevent="updateCounsellor"
                >
                    <div class="w-full mx-auto max-w-[700px] bg-gray-200 sm:rounded-lg p-6 pb-20 relative">
                        <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Profile Images</div>
                        <div class="relative p-1 text-gray-900 text-center bg-gray-300 w-full h-[200px] sm:h-[250px] md:h-[300px]">
                            <div class="absolute p-2 top-2 right-2 flex justify-end items-center">
                                <div
                                    v-if="counsellor.cover"
                                    @click="deleteCover"
                                    class="w-fit p-2 transition duration-75 text-sm tracking-wide rounded cursor-pointer mr-2"
                                    :class="[!coverUrl ? 'hover:bg-green-600 hover:text-green-200 bg-green-300 text-green-700' : 'hover:bg-red-600 hover:text-red-200 bg-red-300 text-red-700']"
                                >{{ !coverUrl ? 'restore' : 'remove' }} image</div>
                                <div
                                    @click="() => clickedChangeFile('cover')"
                                    class="w-fit p-2 hover:bg-gray-600 hover:text-gray-200 transition duration-75 bg-gray-300 text-gray-700 text-sm tracking-wide rounded cursor-pointer"
                                >{{ computedCoverUrl ? 'change' : 'add' }} cover image</div>
                            </div>
                            <img 
                                :src="computedCoverUrl ?? ''" 
                                :alt="'counsellor cover'"
                                v-if="computedCoverUrl"
                                class="w-full h-full object-cover rounded-b-lg"
                            >
                            <div v-else class="text-sm w-full h-full flex justify-center items-center text-gray-600 bg-white rounded-b-lg">no cover image</div>
                        </div>
                        <div class="absolute z-10 bottom-[42px] sm:bottom-[32px] left-2 block xs:flex items-center">

                            <div class="flex items-center z-[1]">
                                <Avatar :size="80" :src="computedAvatarUrl ?? ''" :alt="'counsellor avatar'"/>
                                <div
                                    @click="() => clickedChangeFile('avatar')"
                                    class="w-fit p-2 pl-8 -ml-4 hover:bg-gray-600 hover:text-gray-200 transition duration-75 bg-gray-300 text-gray-700 text-sm tracking-wide rounded cursor-pointer -z-[1]"
                                >{{ computedAvatarUrl ? 'change' : 'add' }} avatar</div>
                                <div
                                    v-if="counsellor.avatar"
                                    @click="deleteAvatar"
                                    class="w-fit p-2 pl-8 -ml-4 transition duration-75 text-sm tracking-wide rounded cursor-pointer -z-[2]"
                                    :class="[!avatarUrl ? 'hover:bg-green-600 hover:text-green-200 bg-green-300 text-green-700' : 'hover:bg-red-600 hover:text-red-200 bg-red-300 text-red-700']"
                                >{{ !avatarUrl ? 'restore' : 'remove' }} avatar</div>
                            </div>

                            <div class="shrink rounded bg-white p-2 text-sm" v-if="updateForm.errors.avatar || updateForm.errors.cover">
                                <InputError class="mt-2" :message="updateForm.errors.avatar" />
                                <InputError class="mt-2" :message="updateForm.errors.cover" />
                            </div>
                        </div>
                    </div>

                    <div class="w-full mx-auto max-w-[700px] bg-gray-200 sm:rounded-lg p-6 mt-4">
                        <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Main Counsellor Information</div>
                        <div class="w-full mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="name" value="Name" />

                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="updateForm.name"
                                autofocus
                            />

                            <div class="mt-2 text-xs text-gray-500">
                                {{
                                    $page.props.auth.user?.fullName
                                        ? 'if you leave this out, the name will be constructed from your information from your user profile.'
                                        : 'This field is required because you have not set the names on your user profile.'
                                }}
                            </div>
                            <InputError class="mt-2" :message="updateForm.errors.name" />
                        </div>

                        <div class="w-full my-4 mx-auto max-w-[400px]">
                            <InputLabel for="about" value="About" />

                            <TextBox
                                id="about"
                                class="mt-1 block w-full"
                                v-model="updateForm.about"
                            />

                            <div class="mt-2 text-xs text-gray-500">What a potential patient should know about you</div>
                            <InputError class="mt-2" :message="updateForm.errors.about" />
                        </div>
                    </div>

                    <div class="w-full mt-4 mx-auto max-w-[700px] bg-gray-200 sm:rounded-lg p-6">
                        <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Contact Information</div>
                        <div class="w-full mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="email" value="Email" />

                            <TextInput
                                id="email"
                                type="email"
                                class="mt-1 block w-full"
                                v-model="updateForm.email"
                                autofocus
                            />

                            <div class="mt-2 text-xs text-gray-500">
                                This field is required if you would want to be verified as a counsellor.
                            </div>
                            <InputError class="mt-2" :message="updateForm.errors.email" />
                        </div>

                        <div class="w-full my-4 mx-auto max-w-[400px]">
                            <InputLabel for="phone" value="Phone Number" />
                            <div class="flex justify-start items-center">
                            <TextInput
                                type="text"
                                id="code"
                                class="mt-1 block mr-2 w-[80px]"
                                placeholder="+233"
                                v-model="code"
                            />

                            <TextInput
                                type="tel"
                                id="phone"
                                placeholder="xxxxxxxxx"
                                class="mt-1 block w-full"
                                v-model="phoneNumber"
                            />
                            </div>

                            <InputError class="mt-2" :message="updateForm.errors.phone" />
                        </div>

                        <div class="w-full my-4 mx-auto max-w-[400px]">
                            
                            <!-- contactVisible -->
                        </div>
                    </div>

                    <div class="w-full mt-4 mx-auto max-w-[700px]">
                        <ProfileProfessionSection
                            class="bg-gray-200 rounded-sm"
                            @on-data="(data) => {
                                updateForm.professionId = data?.id
                            }"
                            :loaded-professions="data.professions"
                            :selected-profession="data.profession"
                            :addedby="{
                                type: 'Counsellor',
                                id: counsellor.id
                            }"
                        />
                    </div>

                    <div class="w-full mt-4 mx-auto max-w-[700px]">
                        <ProfileCaseSection
                            class="bg-gray-200 rounded-sm"
                            @on-data="(data) => {
                                updateForm.selectedCases = [...data]
                            }"
                            :loaded-cases="data.cases"
                            :selected-cases="data.selectedCases"
                            :addedby="{
                                type: 'Counsellor',
                                id: counsellor.id
                            }"
                        />
                    </div>

                    <div class="w-full mt-4 mx-auto max-w-[700px]">
                        <ProfileLanguageSection
                            class="bg-gray-200 rounded-sm"
                            @on-data="(data) => {
                                updateForm.selectedLanguages = [...data]
                            }"
                            :loaded-languages="data.languages"
                            :selected-languages="data.selectedLanguages"
                            :addedby="{
                                type: 'Counsellor',
                                id: counsellor.id
                            }"
                        />
                    </div>

                    <div class="w-full mt-4 mx-auto max-w-[700px]">
                        <ProfileReligionSection
                            class="bg-gray-200 rounded-sm"
                            @on-data="(data) => {
                                updateForm.selectedReligions = [...data]
                            }"
                            :loaded-religions="data.religions"
                            :selected-religions="data.selectedReligions"
                            :addedby="{
                                type: 'Counsellor',
                                id: counsellor.id
                            }"
                        />
                    </div>

                    <div class="w-full flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="!formDataChanged || loading">
                            update
                        </PrimaryButton>
                    </div>
                </form>
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
    
    <input ref="avatar" type="file" name="avatar" id="avatar" class="hidden" accept="image/*" @change="changeAvatar">
    <input ref="cover" type="file" name="cover" id="cover" class="hidden" accept="image/*" @change="changeCover">
</template>
