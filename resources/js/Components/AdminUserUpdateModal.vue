<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update @{{ user.username }} Information</div>
                <hr>
            </div>
            
            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'updating information'"/>
                <form 
                    @submit.prevent="updateUser"
                >
                    <div class="mx-auto w-[90%] px-4 py-2 h-[70vh] overflow-hidden overflow-y-auto">
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="firstName" value="First Name" />

                            <TextInput
                                id="firstName"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="userData.firstName"
                                :required="!!user.firstName"
                            />

                            <InputError class="mt-2" :message="userErrors.firstName" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="lastName" value="Last Name" />

                            <TextInput
                                id="lastName"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="userData.lastName"
                                :required="!!user.lastName"
                            />

                            <InputError class="mt-2" :message="userErrors.lastName" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="otherNames" value="Other Names" />

                            <TextInput
                                id="otherNames"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="userData.otherNames"
                            />

                            <InputError class="mt-2" :message="userErrors.otherNames" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="email" value="Email" />

                            <TextInput
                                id="email"
                                type="email"
                                class="mt-1 block w-full"
                                v-model="userData.email"
                                :required="!!user.email"
                            />

                            <InputError class="mt-2" :message="userErrors.email" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="dob" value="Date of birth" />

                            <TextInput
                                id="dob"
                                type="date"
                                class="mt-1 block w-full"
                                v-model="userData.dob"
                                :max="computedMaxDOB"
                                required
                            />

                            <div v-if="computedUserDOB"  class="mt-2 text-xs text-gray-600 text-end">{{ computedUserDOB }}</div>
                            <InputError class="mt-2" :message="userErrors.dob" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="country" value="Country" />

                            <SelectSearch
                                id="country"
                                class="mt-1 block w-full"
                                @selected="(data) => {
                                    if (data) {
                                        userData.country = data.name
                                        return
                                    }

                                    userData.country = ''
                                }"
                                :options="countries.map(c => {
                                    return {name: c}
                                })"
                                :value="computedFormCountry"
                            />

                            <InputError class="mt-2" :message="userErrors.country" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <label class="flex items-center">
                                <Checkbox name="emailVerified" v-model:checked="userData.emailVerified" />
                                <span class="ms-2 text-sm text-gray-600">email verified.</span>
                            </label>

                            <InputError class="mt-2" :message="userErrors.emailVerified" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
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
</template>

<script setup>
import useAlert from "@/Composables/useAlert"
import useAuth from "@/Composables/useAuth"
import useModal from "@/Composables/useModal"
import Alert from "@/Components/Alert.vue"
import FormLoader from "./FormLoader.vue";
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import { subYears } from 'date-fns';
import PrimaryButton from "./PrimaryButton.vue";
import { computed, ref } from "vue";
import Modal from "./Modal.vue";
import useErrorHandler from "@/Composables/useErrorHandler";
import { watch } from "vue";
import Checkbox from "./Checkbox.vue";
import SelectSearch from "./SelectSearch.vue";


const { goToLogin } = useAuth()
const { setErrorData } = useErrorHandler()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()
const { modalData, showModal } = useModal()

const emits = defineEmits(['close', 'updated'])

const props = defineProps({
    user: {
        default: null
    },
    show: {
        default: false
    }
})

const loading = ref(false)
const userData = ref({
    firstName: '',
    lastName: '',
    country: '',
    otherNames: '',
    email: '',
    emailVerified: null,
    dob: '',
})
const userErrors = ref({
    firstName: '',
    lastName: '',
    country: '',
    otherNames: '',
    email: '',
    emailVerified: '',
    dob: '',
})

const countries = ref([
    'Ghana',
    'Nigeria',
    'Uganda',
    'Rwanda'
])

watch(() => props.show, () => {
    if (!props.show) return

    setUserData()
    getCountries()
})

const computedFormCountry = computed(() => {
    return !userData.value.country ? null : {name: userData.value.country}
})
const computedMaxDOB = computed(() => {
    return subYears(new Date(), 10).toISOString().slice(0, 10)
})
const computedUserDOB = computed(() => {
    return userData.value.dob ? (new Date(userData.value.dob)).toDateString() : ''
})

function getDate(dob) {
    const dateSplit = new Date(dob).toISOString().split('T')

    if (dateSplit.length) return dateSplit[0]

    return ''
}

function closeModal() {
    clearUserData()
    emits('close')
}
  
async function updateUser() {
    loading.value = true
    
    await axios
    .post(route(`admin.users.update`, { userId: props.user.id}), {
        ...userData.value
    })
    .then((res) => {
        console.log(res)
        setSuccessAlertData({
            message: `${props.user.username}'s information has successfully been updated.`,
        })
        emits('updated', res.data.user)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.status == 422) {
            setErrorData(userErrors, err.response.data.errors, [
                'firstName', 'lastName', 'country',
                'otherNames', 'email', 'emailVerified', 'dob',
            ])
            return
        }
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
            })
            return
        }

        setFailedAlertData({
            message: 'Something unfortunate happened. Please try again later.',
        })
    })
    .finally(() => {
        loading.value = false
    })
}
 
function clearUserData() {
    userData.value.firstName = ''
    userData.value.lastName = ''
    userData.value.otherNames = ''
    userData.value.email = ''
    userData.value.dob = ''
    userData.value.country = ''
    userData.value.emailVerified = null
}

function setUserData() {
    userData.value.firstName = props.user.firstName
    userData.value.lastName = props.user.lastName
    userData.value.otherNames = props.user.otherNames
    userData.value.email = props.user.email
    userData.value.country = props.user.country
    userData.value.dob = props.user.dob ? getDate(props.user.dob) : ''
    userData.value.emailVerified = props.user.emailVerified
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

</script>