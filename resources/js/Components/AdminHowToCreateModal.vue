<template>
    <Modal
        :show="show"
        @close="closeHowToModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Create How-To</div>
                <hr>
            </div>
            
            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'creating how-to'"/>
                <form 
                    @submit.prevent="createHowTo"
                >
                    <div class="mx-auto w-[90%] px-4 py-2 h-[70vh] overflow-hidden overflow-y-auto">
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="name" value="Name" />

                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="howToData.name"
                            />

                            <InputError class="mt-2" :message="howToErrors.name" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="description" value="Description" />

                            <TextBox
                                id="description"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="howToData.description"
                            />

                            <InputError class="mt-2" :message="howToErrors.description" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <div>Pages</div>

                            <div class="flex p-2 justify-start space-x-3 overflow-hidden overflow-x-auto items-center my-4">
                                <div
                                    v-for="(page, idx) in appPages"
                                    :key="idx"
                                    class="relative select-none w-fit transition hover:bg-gray-600 hover:text-gray-200 bg-gray-200 text-gray-600 text-nowrap text-center p-2 cursor-pointer text-sm rounded"
                                    @click="() => addPage(page)"
                                >
                                    {{ page }}
                                </div>
                            </div>

                            <div class="text-center text-gray-600 text-sm font-bold my-4">selected pages</div>
                            <div
                                v-if="pages.length"
                                class="flex p-2 justify-start space-x-6 overflow-hidden overflow-x-auto items-center my-4"
                            >
                                <div
                                    v-for="(page, idx) in pages"
                                    :key="idx"
                                    class="relative select-none transition w-fit bg-gray-200 text-gray-600 text-nowrap text-center p-2 cursor-pointer text-sm rounded"
                                >
                                    <div class="">{{ page }}</div>
                                    <div class="absolute w-6 h-6 rounded-full p-2 bg-gray-200 text-gray-600 cursor-pointer flex justify-center items-center -right-3 -top-2 hover:bg-gray-600 hover:text-gray-200"
                                        @click="() => removePage(page)"
                                    >x</div>
                                </div>
                            </div>

                            <div v-else class="text-center text-gray-600 text-sm">no pages yet</div>

                            <InputError class="mt-2" :message="howToErrors.page" />
                        </div>
                        
                        <div class="my-4 mx-auto max-w-[400px]">
                            <div>Steps</div>

                            <div class="flex p-2 justify-end items-center my-2">
                                <PrimaryButton @click.prevent="addHowToStep">add how-to-step</PrimaryButton>
                            </div>

                            <div
                                v-if="howToData.howToSteps?.length"
                                class="flex justify-start items-center p-2 overflow-hidden overflow-x-auto space-x-3"
                            >
                                <AdminHowToStepComponent
                                    v-for="(howToStep, idx) in howToData.howToSteps.sort((a, b) => a.position - b.position)"
                                    :key="howToStep.position"
                                    :howToStep="howToStep"
                                    @updated="(data) => updateHowToStep(data, howToStep.position, idx)"
                                    class="w-[250px] sm:w-[400px] shrink-0"
                                    :canEdit="true"
                                />
                            </div>

                            <div v-else class="text-center text-gray-600 text-sm mt-2 h-24 flex justify-center items-center">no steps yet</div>

                            <InputError class="mt-2" :message="howToErrors.howToSteps" />
                        </div>
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

    <AdminHowToStepCreateModal
        :show="modalData.show && modalData.type == 'how-to-step'"
        :positions="howToData.howToSteps.map((h) => h.position)"
        :elementIds="howToData.howToSteps.map((h) => h.elementId)"
        @created="createdHowToStep"
        @close="closeModal"
    />
</template>

<script setup>
import useAlert from "@/Composables/useAlert"
import useAuth from "@/Composables/useAuth"
import Alert from "@/Components/Alert.vue"
import FormLoader from "./FormLoader.vue";
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { ref } from "vue";
import Modal from "./Modal.vue";
import useErrorHandler from "@/Composables/useErrorHandler";
import AdminHowToStepComponent from "./AdminHowToStepComponent.vue";
import AdminHowToStepCreateModal from "./AdminHowToStepCreateModal.vue";
import useModal from "@/Composables/useModal";
import useHowToPages from "@/Composables/useHowToPages";
import TextBox from "./TextBox.vue";


const { goToLogin } = useAuth()
const { appPages } = useHowToPages()
const { showModal, modalData, closeModal } = useModal()
const { setErrorData } = useErrorHandler()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()

const emits = defineEmits(['close', 'created'])

const props = defineProps({
    show: {
        default: false
    }
})

const pages = ref([])
const loading = ref(false)
const howToData = ref({
    name: '',
    description: '',
    page: '',
    howToSteps: [],
})
const howToErrors = ref({
    name: '',
    description: '',
    page: '',
    howToSteps: '',
})

function closeHowToModal() {
    clearHowToData()
    emits('close')
}

function updateHowToStep(howToStep, oldPosition, idx) {
    const otherIdx = howToData.value.howToSteps.findIndex((s) => s.position == howToStep.position)
    if (howToStep.position !== oldPosition && otherIdx > -1)
        howToData.value.howToSteps[otherIdx].position = oldPosition

    howToData.value.howToSteps.splice(idx, 1, howToStep)
}

function addHowToStep() {
    showModal('how-to-step')
}

function addPage(page) {
    if (pages.value.findIndex((p) => p == page) > -1) return

    pages.value.unshift(page)
}

function removePage(page) {
    pages.value.splice(pages.value.findIndex((p) => p == page), 1)
}

function createdHowToStep(howToStep) {
    if (howToData.value.howToSteps.findIndex((s) => s.position == howToStep.position) > -1) {
        setFailedAlertData({
            message: "You cannot add a step with a position that already exists",
            time: 4000
        })
        return
    }

    howToData.value.howToSteps = [howToStep, ...howToData.value.howToSteps]
}
  
async function createHowTo() {
    loading.value = true

    if (pages.value?.length)
        howToData.value.page = pages.value.join(', ')
    else
        howToData.value.page = 'all'
    
    await axios
        .post(route(`admin.how-tos.create`), {
            ...howToData.value
        }, {
            headers: {'Content-Type': 'multipart/form-data'},
        })
        .then((res) => {
            console.log(res)
            setSuccessAlertData({
                message: `'${howToData.value.name}' how-to has successfully been created.`,
                time: 5000
            })
            emits('created', res.data.howTo)
            closeHowToModal()
        })
        .catch((err) => {
            console.log(err)
            if (err.response?.status == 422) {
                setErrorData(howToErrors, err.response.data.errors, ['name', 'about'])
                return
            }
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 4000,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 4000,
            })
        })
        .finally(() => {
            loading.value = false
        })
}
 
function clearHowToData() {
    howToData.value.name = ''
    howToData.value.page = ''
    howToData.value.description = ''
    howToData.value.howToSteps = []
}

</script>