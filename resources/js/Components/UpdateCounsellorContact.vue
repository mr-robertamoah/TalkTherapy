<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm, usePage } from "@inertiajs/vue3";
import { ref, watch, watchEffect } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import Checkbox from "./Checkbox.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Modal from "./Modal.vue";
import { computed } from "vue";

const { modalData, closeModal } = useModal()
const { alertData, setAlertData, clearAlertData } = useAlert()

const updateForm = useForm({
    phone: '',
    email: '',
    contactVisible: false,
})

const emits = defineEmits(['closeModal'])

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    counsellor: {
        default: null,
    }
})

const backendPhone = ref('')
const code = ref('')
const phoneNumber = ref('')
const loading = ref(false)
const useUserEmail = ref(false)
const phoneData = ref(null)
const formDataChanged = ref(false)

watch(
    () => props.show,
    () => {
        modalData.value.show = props.show

        if (props.show) setUpdateData()
    }
)
watch(
    () => updateForm.email,
    () => {
        if (updateForm.email !== usePage().props.auth.user?.email)
            useUserEmail.value = false
    }
)
watch(
    () => useUserEmail.value,
    () => {
        if (useUserEmail.value)
            updateForm.email = usePage().props.auth.user?.email ?? ''
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
    () => phoneNumber.value,
    () => {
        setBackEndPhoneNumber()

        updateForm.clearErrors('phone')
    }
)
watchEffect(() => {
    formDataChanged.value = false
    
    if (updateForm.email && updateForm.email !== props.counsellor.email) {
        updateForm.clearErrors('email')
        return formDataChanged.value = true
    }
    
    if (backendPhone.value && backendPhone.value !== props.counsellor.phone) {
        updateForm.clearErrors('phone')
        return formDataChanged.value = true
    }

    if (updateForm.contactVisible !== props.counsellor?.contactVisible)
        return formDataChanged.value = true
})

const canUseUserEmail = computed(() => {
    const user = usePage().props.auth.user

    return (user.email && user.emailVerifiedAt && user.email !== updateForm.email) ? true : false
})

function closeThisModal() {
    resetUpdateData()
    emits('closeModal')
    closeModal()
}

function setBackEndPhoneNumber() {
    backendPhone.value = `${code.value}${phoneNumber.value}`
}
 
function updateCounsellor() { 
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

    updateForm.post(route(`counsellor.update`, { counsellorId: props.counsellor?.id}), {
        onSuccess: () => {
            closeThisModal()
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}

function setUpdateData() {
    phoneData.value = constructPhoneNumberForFrontEnd(props.counsellor.phone)
    
    if (props.counsellor?.phone) {
        phoneNumber.value = phoneData.value.phone
        code.value = phoneData.value.code
    }

    if (props.counsellor?.email)
        updateForm.email = props.counsellor.email
    
    updateForm.contactVisible = props.counsellor.contactVisible
}

function constructPhoneNumberForFrontEnd(phone) {
    if (!phone?.length) return {code: '', phone: ''}
    console.log(phone)
    if (phone.length == 10)
        return {code: '', phone}
    
    let idx = phone.length - 9
    return {
        code: phone.slice(0, idx),
        phone: phone.slice(idx,)
    }
}

function resetUpdateData() {
    updateForm.reset(
        'email', 'phone', 'contactVisible'
    )
}

function thereIsNoData() {
    if (
        updateForm.email !== props.counsellor?.email ||
        updateForm.phone !== props.counsellor?.phone ||
        updateForm.contactVisible !== props.counsellor?.contactVisible
    ) return false

    return true
}
</script>

<template>
    <Modal
        :show="modalData.show"
        @close="closeThisModal"
    >
        <div class="p-4">
            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Counsellor Account</div>
                <hr>
            </div>

            <FormLoader class="top-14 mx-auto" :show="loading" :text="'updating contacts'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form 
                    @submit.prevent="updateCounsellor"
                >
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
                            <label class="flex items-center mt-2" v-if="canUseUserEmail">
                                <Checkbox name="remember" v-model:checked="useUserEmail" />
                                <span class="ms-2 text-sm text-gray-600">use your user account email.</span>
                            </label>

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
                            
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="updateForm.contactVisible" />
                                <span class="ms-2 text-sm text-gray-600">Contact should be public.</span>
                            </label>
                        </div>
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
</template>