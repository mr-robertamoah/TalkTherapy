<script setup>
import { ref, watch } from 'vue';
import FormLoader from './FormLoader.vue';
import InputError from './InputError.vue';
import InputLabel from './InputLabel.vue';
import Modal from './Modal.vue';
import PrimaryButton from './PrimaryButton.vue';
import TextBox from './TextBox.vue';
import TextInput from './TextInput.vue';
import useErrorHandler from '@/Composables/useErrorHandler';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';
import Select from './Select.vue';
import { onBeforeMount } from 'vue';

const { setErrorData, clearError } = useErrorHandler()
const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    addedbyType: {
        type: String,
        default: 'Counsellor'
    },
    addedbyId: {
        type: Number,
        default: 0
    },
})

const emits = defineEmits(['closeModal', 'afterCreating'])

onBeforeMount(() => {
    const c = localStorage.getItem('countries')
    if (c) countries.value = c.split(',')
    getCountries()
})

const backendPhone = ref('')
const code = ref('')
const phoneNumber = ref('')
const loading = ref(false)
const licensingAuthorityData = ref({
    name: '',
    about: '',
    type: '',
    licenseType: '',
    country: '',
    phone: '',
    other: '',
    email: '',
})
const licensingAuthorityErrors = ref({
    name: '',
    about: '',
    type: '',
    licenseType: '',
    country: '',
    phone: '',
    other: '',
    email: '',
})
const countries = ref([
    'Ghana',
    'Nigeria',
    'Uganda',
    'Rwanda'
])

watch(() => licensingAuthorityData.value.name, () => {
    if (
        licensingAuthorityData.value.name?.length &&
        licensingAuthorityErrors.value.name?.length
    ) clearError(licensingAuthorityErrors, 'name')
})
watch(
    () => code.value,
    () => {
        setBackEndPhoneNumber()

        clearError(licensingAuthorityErrors, 'phone')
    }
)
watch(
    () => phoneNumber.value,
    () => {
        setBackEndPhoneNumber()

        clearError(licensingAuthorityErrors, 'phone')
    }
)

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

function setBackEndPhoneNumber() {
    backendPhone.value = `${code.value}${phoneNumber.value}`
}

function closeModal() {
    clearCaseData()
    emits('closeModal')
}
 
function clearCaseData() {
    licensingAuthorityData.value.name = ''
    licensingAuthorityData.value.description = ''
}
  
async function createLicensingAuthory() {

    if (
        !(code.value) &&
        !(phoneNumber.value)
    ) {
        setAlertData({
            show: true,
            message: 'Please provide email or phone number of licensing body.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(licensingAuthorityData.value.name?.trim())
    ) {
        setAlertData({
            show: true,
            message: 'Please the name of the licensing authority.',
            type: 'failed',
        })
        return
    }
    
    if (
        !(licensingAuthorityData.value.type?.trim())
    ) {
        setAlertData({
            show: true,
            message: 'Please the type (governmental, international, etc) the licensing authority.',
            type: 'failed',
        })
        return
    }

    if (
        !licensingAuthorityData.value.type
    ) {
        setAlertData({
            show: true,
            message: 'Select the type of institution this licensing authority is (governmental, international, etc).',
            type: 'failed',
        })
        return
    }

    if (
        licensingAuthorityData.value.type == 'OTHER'
    ) {
        setAlertData({
            show: true,
            message: 'Please provide the type licensing authority since you selected OTHER.',
            type: 'failed',
        })
        return
    }

    // admin page
    // add more info to licensing authority badge on consellor verification modal

    loading.value = true

    const addedbyData = {}

    if (props.addedbyId) {
        addedbyData['addedbyType'] = props.addedbyType
        addedbyData['addedbyId'] = props.addedbyId
    }
    
    licensingAuthorityData.value.phone = backendPhone.value

    await axios
    .post(route(`licensing_authorities.create`), {
        ...addedbyData,
        ...licensingAuthorityData.value
    })
    .then((res) => {
        console.log(res)
        emits('afterCreating', res.data.licensingAuthority)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.status == 422) {
            setErrorData(licensingAuthorityErrors, err.response.data.errors, ['name', 'description'])
            return
        }
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
            })
            return
        }

        setAlertData({
            message: 'Something unfortunate happened. Please try again later.',
            type: 'failed',
            show: true,
        })
    })
    .finally(() => {
        loading.value = false
    })
}
</script>

<template>
    
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Create Licensing Authority</div>
                <hr>
            </div>
            
            <div class="overflow-hidden overflow-y-auto h-[80vh]">
                <FormLoader class="mx-auto" :show="loading" :text="'creating licensing authority'"/>
                <form 
                    @submit.prevent="createLicensingAuthory"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.name"
                            autofocus
                            required
                        />

                        <InputError class="mt-2" :message="licensingAuthorityErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="about" value="About" />

                        <TextBox
                            id="about"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.about"
                        />

                        <InputError class="mt-2" :message="licensingAuthorityErrors.about" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="type" value="Type" />

                        <Select
                            id="type"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.type"
                            autocomplete="type"
                            :options="['Governmental', 'International', 'Religious', 'Other']"
                            :default-option="'select type'"
                            required
                        />

                        <TextInput
                            v-if="licensingAuthorityData.type == 'OTHER'"
                            id="other"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.other"
                            :required="licensingAuthorityData.type == 'OTHER'"
                        />

                        <InputError class="mt-2" :message="licensingAuthorityErrors.type" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="licenseType" value="License Type" />

                        <Select
                            id="licenseType"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.licenseType"
                            autocomplete="licenseType"
                            :options="['Number', 'File', 'Both']"
                            :default-option="'select license type'"
                            required
                        />

                        <div class="mt-2 text-xs text-gray-500">
                            Number represents ID, File means you are required to upload a file, and both means you can do any one of them or both.
                        </div>
                        <InputError class="mt-2" :message="licensingAuthorityErrors.licenseType" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="country" value="Country" />

                        <Select
                            id="country"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.country"
                            autocomplete="country"
                            :options="countries"
                            :default-option="'select country'"
                        />

                        <InputError class="mt-2" :message="licensingAuthorityErrors.country" />
                    </div>

                    <div class="w-full mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="email" value="Email" />

                        <TextInput
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="licensingAuthorityData.email"
                        />

                        <InputError class="mt-2" :message="licensingAuthorityErrors.email" />
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

                        <InputError class="mt-2" :message="licensingAuthorityErrors.phone" />
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                            create
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
</template>