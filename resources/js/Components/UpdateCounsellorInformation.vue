<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, watchEffect } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Modal from "./Modal.vue";
import ProfileProfessionSection from "@/Pages/Profile/Partials/ProfileProfessionSection.vue";
import TextBox from "./TextBox.vue";

const { modalData, closeModal } = useModal()
const { alertData, setAlertData, clearAlertData } = useAlert()

const updateForm = useForm({
    name: '',
    about: '',
    professionId: null,
})

const emits = defineEmits(['closeModal'])

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    counsellor: {
        default: null,
    },
    professions: {
        default: []
    },
})

const loading = ref(false)
const formDataChanged = ref(false)
const data = ref({
    profession: null,
    professions: [],
})

watch(
    () => props.show,
    () => {
        modalData.value.show = props.show

        if (props.show) setUpdateData()
    }
)
watchEffect(() => {
    formDataChanged.value = false

    if (updateForm.about && updateForm.about !== props.counsellor.about) {
        updateForm.clearErrors('about')
        return formDataChanged.value = true
    }
    
    if (updateForm.name && updateForm.name !== props.counsellor.name) {
        updateForm.clearErrors('name')
        return formDataChanged.value = true
    }
    
    if (updateForm.professionId !== props.counsellor.profession?.id) {
        updateForm.clearErrors('professionId')
        return formDataChanged.value = true
    }
})

function closeThisModal() {
    resetUpdateData()
    emits('closeModal')
    closeModal()
}
 
function updateCounsellor() { 
    
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
    data.value.professions = [...props.professions]

    if (props.counsellor?.profession)
        data.value.profession = {...props.counsellor.professions}
    
    if (props.counsellor?.name)
        updateForm.name = props.counsellor.name

    if (props.counsellor?.about)
        updateForm.about = props.counsellor.about
}

function resetUpdateData() {
    updateForm.reset(
        'name', 'about', 'professionId'
    )
}

function thereIsNoData() {
    if (
        updateForm.name !== props.counsellor?.name ||
        updateForm.about !== props.counsellor?.about ||
        updateForm.professionId !== props.counsellor?.profession?.id
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

            <FormLoader class="top-14 mx-auto" :show="loading" :text="'updating account information...'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form 
                    @submit.prevent="updateCounsellor"
                >
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

                    <div class="w-full mt-4 mx-auto max-w-[700px]">
                        <ProfileProfessionSection
                            class="bg-gray-200 rounded-sm"
                            @on-data="(data) => {
                                updateForm.professionId = data?.id
                            }"
                            :loaded-professions="data.professions"
                            :selected-profession="counsellor.profession"
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
</template>