<script setup>
import useModal from "@/Composables/useModal"
import { ref, watch } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useErrorHandler from "@/Composables/useErrorHandler";
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import { usePage } from "@inertiajs/vue3";
import PrimaryButton from "./PrimaryButton.vue";
import StyledLink from "./StyledLink.vue";
import CounsellorCreationSteps from "./CounsellorCreationSteps.vue";
import { computed } from "vue";

const { modalData, closeModal, showModal } = useModal()
const { setErrorData } = useErrorHandler()
const { alertData, clearAlertData, setAlertData } = useAlert()

defineProps({
    text: {
        type: String,
        default: 'become'
    }
})

const emits = defineEmits(['successful'])

const step = ref(usePage().props.counsellorCreationStep)
const counsellorId = ref(usePage().props.auth.user?.counsellor?.id ?? 0)
const loading = ref(false)
const registrationData = ref({
    'name': '',
    'about': '',
})
const registrationErrors = ref({
    'name': '',
    'about': '',
})

watch(
    () => usePage().props.counsellorCreationStep,
    () => {
        step.value = usePage().props.counsellorCreationStep
    }
)
watch(
    () => usePage().props.auth.user?.counsellor?.id,
    () => {
        counsellorId.value = usePage().props.auth.user?.counsellor?.id ?? 0
    }
)
watch(
    () => registrationData.value.name,
    () => {
        if (registrationErrors.value.name?.length)
            registrationErrors.value.name = ''
    }
)
 
async function becomeCounsellor() {
    loading.value = true

    await axios
    .post(route(`counsellors.create`), {
        ...registrationData.value
    })
    .then((res) => {
        console.log(res)
        
        setAlertData({
            message: 'Your counsellor account has been created successfully. Get the account verified.',
            type: 'success',
            show: true,
        })
        counsellorId.value = res.data.counsellor.id
        emits('successful', res.data.counsellor)
        step.value = 1
        clearData()
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.data?.message) {
            setAlertData({
                message: err.response.data.message,
                type: 'failed',
                show: true,
            })
            return
        }

        if (err.response?.status == 422 && err.response?.data?.errors) {
            setErrorData(registrationErrors, err.response.data.errors, ['name', 'about'])
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

const nameError = computed(() => registrationErrors.value.name)

function clearData() {
    registrationData.value.name = ''
    registrationData.value.about = ''
}

function clickedBecome() {
    showModal('counsellor')
}
</script>

<template>
    <div>
        <PrimaryButton v-if="step < 1" @click="clickedBecome" class="capitalize">{{ text }}</PrimaryButton>
        <StyledLink
            v-else-if="step == 1"
            :href="route('counsellor.show', counsellorId)"
            :text="'verify counsellor account'"
        />
        <div
            class="p-2 bg-green-700 text-green-200 rounded select-none cursor-none w-fit"
            v-else-if="step == 4">certified counsellor</div>
        <div
            class="p-2 bg-blue-700 text-blue-200 rounded select-none cursor-none w-fit"
            v-else-if="step > 1">verified counsellor</div>
        <Modal
            :show="modalData.show"
            @close="closeModal"
        >
            <div class="p-4">

                <div class="w-full mt-2 mb-4">
                    <div
                        class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                    >Become Counsellor</div>
                    <hr>
                </div>
                <p class="mt-1 text-sm text-gray-600" v-if="!$page.props.auth.user?.isAdult">
                    You must be an adult (above 18 years), hence, make sure you update your date of birth before you continue with this registration.
                </p>

                <CounsellorCreationSteps
                    class="mt-8"
                    :current-step="1"
                    :light="false"
                />

                <div class="relative">
                    <FormLoader class="mx-auto" :show="loading" :text="'getting you registered'"/>
                    <form 
                        @submit.prevent="becomeCounsellor"
                    >

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="name" value="Name" />

                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="registrationData.name"
                                :required="$page.props.auth.user?.fullName ? false : true"
                                autofocus
                            />

                            <div class="mt-2 text-xs text-gray-500">
                                {{
                                    $page.props.auth.user?.fullName
                                        ? 'if you leave this out, the name will be constructed from your information from your user profile.'
                                        : 'This field is required because you have not set the names on your user profile.'
                                }}
                            </div>
                            <InputError class="mt-2" :message="nameError" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="about" value="About" />

                            <TextBox
                                id="about"
                                class="mt-1 block w-full"
                                v-model="registrationData.about"
                            />

                            <div class="mt-2 text-xs text-gray-500">What a potential patient should know about you</div>
                            <InputError class="mt-2" :message="registrationErrors.about" />
                        </div>

                        <div class="flex items-center justify-end mt-4">

                            <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                                register
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
    </div>
</template>