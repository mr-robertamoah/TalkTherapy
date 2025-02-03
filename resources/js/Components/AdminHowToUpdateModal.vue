<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update {{ howTo.name }} How-To</div>
                <hr>
            </div>
            
            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'updating how-to'"/>
                <form 
                    @submit.prevent="updateHowTo"
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
                            <InputLabel for="page" value="Page" />

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

                            <InputError class="mt-2" :message="howToErrors.page" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <div>Steps</div>

                            <div class="flex p-2 justify-end items-center my-2">
                                <PrimaryButton @click.prevent="() => showModal('how-to-step')">add how-to-step</PrimaryButton>
                            </div>

                            <div
                                v-if="howToData.howToSteps?.length"
                                class="flex justify-start items-center p-2 overflow-hidden overflow-x-auto space-x-3"
                            >
                                <AdminHowToStepComponent
                                    v-for="(howToStep, idx) in howToData.howToSteps.sort((a, b) => a.position - b.position)"
                                    :key="idx"
                                    :howToStep="howToStep"
                                    @updated="(data) => updateHowToStep(data, howToStep.position, idx)"
                                    @deleted="(data) => deleteHowToStep(data, howToStep.position, idx)"
                                    class="w-[250px] sm:w-[400px] shrink-0"
                                    :canEdit="true"
                                    :file="howTo.howToSteps.filter((h) => h.id == howToStep.id)[0].file"
                                />
                            </div>

                            <div v-if="addedHowToSteps.length" class="rounded bg-green-200 my-4 pb-2">
                                <div class="text-sm text-center text-green-600 my-2">added how-to-steps</div>

                                <div
                                    class="flex justify-start items-center p-2 overflow-hidden overflow-x-auto space-x-3"
                                >
                                    <AdminHowToStepComponent
                                        v-for="(howToStep, idx) in addedHowToSteps"
                                        :key="idx"
                                        :howToStep="howToStep"
                                        @remove="(data) => removeHowToStep(data, idx)"
                                        class="w-[250px] sm:w-[400px] shrink-0"
                                        :canEdit="false"
                                        :canRemove="true"
                                    />
                                </div>
                            </div>

                            <div v-if="deletedHowToSteps.length" class="rounded bg-red-200 my-4 pb-2">
                                <div class="text-sm text-center text-red-600 my-2">delete how-to-steps</div>

                                <div
                                    class="flex justify-start items-center p-2 overflow-hidden overflow-x-auto space-x-3"
                                >
                                    <AdminHowToStepComponent
                                        v-for="(howToStep, idx) in deletedHowToSteps"
                                        :key="idx"
                                        :howToStep="howToStep"
                                        @restore="(data) => restoreHowToStep(data, idx)"
                                        class="w-[250px] sm:w-[400px] shrink-0"
                                        :canEdit="false"
                                        :canRestore="true"
                                    />
                                </div>
                            </div>

                            <div
                                v-if="!howToData.howToSteps?.length && !addedHowToSteps?.length && !deletedHowToSteps?.length"
                                class="text-center text-gray-600 text-sm mt-2 h-24 flex justify-center items-center"
                            >no steps yet</div>

                            <InputError class="mt-2" :message="howToErrors.howToSteps" />
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

    <AdminHowToStepCreateModal
        :show="modalData.show && modalData.type == 'how-to-step'"
        :positions="computedPositions"
        @created="addHowToStep"
        @close="() => {
            modalData.show = false
            modalData.type = ''
        }"
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
import { watch } from "vue";
import AdminHowToStepComponent from "./AdminHowToStepComponent.vue";
import TextBox from "./TextBox.vue";
import AdminHowToStepCreateModal from "./AdminHowToStepCreateModal.vue";
import useModal from "@/Composables/useModal";
import { computed } from "vue";
import useHowToPages from "@/Composables/useHowToPages";


const { goToLogin } = useAuth()
const { appPages } = useHowToPages()
const { setErrorData } = useErrorHandler()
const { modalData, showModal } = useModal()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()

const emits = defineEmits(['close', 'updated'])

const props = defineProps({
    howTo: {
        default: null
    },
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
const deletedHowToSteps = ref([])
const addedHowToSteps = ref([])

watch(() => props.show, () => {
    if (!props.show) return

    setHowToData()
})

const computedPositions = computed(() => {
    const currentPositions = howToData.value.howToSteps.map((h) => h.position)
    const addedPositions = addedHowToSteps.value.map((h) => h.position)

    return [...currentPositions, ...addedPositions]
})

function closeModal() {
    clearHowToData()
    emits('close')
}

function updateHowToStep(howToStep, oldPosition, idx) {
    let otherIdx = howToData.value.howToSteps.findIndex((s) => s.position == howToStep.position)
    if (howToStep.position !== oldPosition && otherIdx > -1)
        howToData.value.howToSteps[otherIdx].position = oldPosition

    otherIdx = addedHowToSteps.value.findIndex((s) => s.position == howToStep.position)
    if (howToStep.position !== oldPosition && otherIdx > -1)
        addedHowToSteps.value[otherIdx].position = oldPosition

    howToData.value.howToSteps.splice(idx, 1, howToStep)
}

function deleteHowToStep(howToStep, oldPosition, idx) {
    howToData.value.howToSteps.map((h) => h.position > oldPosition ? {...h, position: h.position - 1} : h)
    howToData.value.howToSteps.splice(idx, 1)
    deletedHowToSteps.value = [howToStep, ...deletedHowToSteps.value]
}

function restoreHowToStep(howToStep, idx) {
    howToData.value.howToSteps.map((h) => h.position >= howToStep.position ? {...h, position: h.position + 1} : h)
    howToData.value.howToSteps.unshift(howToStep)
    deletedHowToSteps.value.splice(idx, 1)
}

function addHowToStep(howToStep) {
    howToData.value.howToSteps = [
        ...howToData.value.howToSteps.map((h) => h.position >= howToStep.position ? {...h, position: h.position + 1} : h)]

    addedHowToSteps.value = [howToStep, ...addedHowToSteps.value]
}

function removeHowToStep(howToStep, idx) {
    howToData.value.howToSteps = [
        ...howToData.value.howToSteps.map((h) => h.position > howToStep.position ? {...h, position: h.position - 1} : h)]

    addedHowToSteps.value.splice(idx, 1)
}

function addPage(page) {
    if (pages.value.findIndex((p) => p == page) > -1) return

    pages.value.unshift(page)
}

function removePage(page) {
    pages.value.splice(pages.value.findIndex((p) => p == page), 1)
}
  
async function updateHowTo() {
    loading.value = true

    if (pages.value?.length)
        howToData.value.page = pages.value.join(', ')
    else
        howToData.value.page = 'all'
    
    const howToSteps = howToData.value.howToSteps.map((h) => h.file.url ? null : {...h})
    const deletedSteps = deletedHowToSteps.value.filter((h) => h.id).map((h) => ({id: h.id, position: h.position}))
    const addedSteps = addedHowToSteps.value.map((h) => ({...h}))
    await axios
    .post(route(`admin.how-tos.update`, { howToId: props.howTo.id}), {
        ...howToData.value,
        howToSteps,
        deletedSteps,
        addedSteps
    }, {
        headers: {'Content-Type': 'multipart/form-data'},
    })
    .then((res) => {
        console.log(res)
        setSuccessAlertData({
            message: `'${props.howTo.name}' how-to has successfully been updated.`,
        })
        emits('updated', res.data.howTo)
        closeModal()
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
 
function clearHowToData() {
    howToData.value.name = ''
    howToData.value.page = ''
    howToData.value.description = ''
    howToData.value.howToSteps = []
}

function setHowToData() {
    howToData.value.name = props.howTo.name
    howToData.value.page = props.howTo.page
    pages.value = props.howTo.page.split(', ')
    howToData.value.description = props.howTo.description
    howToData.value.howToSteps = [...props.howTo.howToSteps.map((h) => ({...h}))]
}

</script>