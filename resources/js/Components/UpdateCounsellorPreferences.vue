<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, watchEffect } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Modal from "./Modal.vue";
import ProfileReligionSection from "@/Pages/Profile/Partials/ProfileReligionSection.vue";
import ProfileLanguageSection from "@/Pages/Profile/Partials/ProfileLanguageSection.vue";
import ProfileCaseSection from "@/Pages/Profile/Partials/ProfileCaseSection.vue";

const { modalData, closeModal } = useModal()
const { alertData, setAlertData, clearAlertData } = useAlert()

const updateForm = useForm({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
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
    cases: {
        default: []
    },
    languages: {
        default: [] 
    },
    religions: {
        default: []
    },
})

const data = ref({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
    cases: [],
    languages: [],
    religions: [],
})
const loading = ref(false)
const formDataChanged = ref(false)

watch(
    () => props.show,
    () => {
        modalData.value.show = props.show

        if (props.show) setUpdateData()
    }
)
watchEffect(() => {
    formDataChanged.value = false
    
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

function closeThisModal() {
    resetUpdateData()
    emits('closeModal')
    closeModal()
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
    data.value.cases = [...props.cases]
    data.value.languages = [...props.languages]
    data.value.religions = [...props.religions]

    if (props.counsellor?.cases?.length)
        data.value.selectedCases = [...props.counsellor.cases]
    if (props.counsellor?.languages?.length)
        data.value.selectedLanguages = [...props.counsellor.languages]
    if (props.counsellor?.religions?.length)
        data.value.selectedReligions = [...props.counsellor.religions]
}

function resetUpdateData() {
    updateForm.reset(
        'selectedCases', 'selectedLanguages', 'selectedReligions'
    )
}

function thereIsNoData() {
    if (
        updateForm.selectedLanguages.length || updateForm.selectedReligions.length ||
        updateForm.selectedCases.length
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

            <FormLoader class="top-14 mx-auto" :show="loading" :text="'updating preferences'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form 
                    @submit.prevent="updateCounsellor"
                >
                    <div class="w-full mt-4 mx-auto max-w-[700px]">
                        <ProfileCaseSection
                            class="bg-gray-200 rounded-sm"
                            @on-data="(data) => {
                                updateForm.selectedCases = [...data.map(d => d.id)]
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
                                updateForm.selectedLanguages = [...data.map(d => d.id)]
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
                                updateForm.selectedReligions = [...data.map(d => d.id)]
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
</template>