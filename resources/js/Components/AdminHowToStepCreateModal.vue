<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Add How-To-Step</div>
                <hr>
            </div>
            
            <div>
                <form 
                    @submit.prevent="createHowToStep"
                >
                    <div class="mx-auto w-[90%] px-4 py-2 h-[70vh] overflow-hidden overflow-y-auto">
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="name" value="Name" />

                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="howToStepData.name"
                            />

                            <InputError class="mt-2" :message="howToStepErrors.name" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="description" value="Description" />

                            <TextBox
                                id="description"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="howToStepData.description"
                            />

                            <InputError class="mt-2" :message="howToStepErrors.description" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="position" value="Position" />

                            <TextInput
                                id="position"
                                type="number"
                                class="mt-1 block w-full"
                                min="1"
                                v-model="howToStepData.position"
                            />

                            <InputError class="mt-2" :message="howToStepErrors.position" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="elementId" value="Element ID" />

                            <TextInput
                                id="elementId"
                                type="text"
                                class="mt-1 block w-full"
                                min="1"
                                v-model="howToStepData.elementId"
                            />

                            <InputError class="mt-2" :message="howToStepErrors.elementId" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="file" value="File" />

                            <div class="flex justify-end items-center w-full mb-4 mt-2">
                                <PrimaryButton
                                    @click.prevent="() => fileInput.click()"
                                >{{ howToStepData.file ? 'change' : 'add' }}</PrimaryButton>
                            </div>
                            <FilePreview
                                v-if="howToStepData.file"
                                :file="howToStepData.file"
                                class="h-[200px] w-[200px] mx-auto my-2"
                                :show-remove="true"
                                @remove-file="() => howToStepData.file = null"
                            />
                            
                            <div v-else class="text-center text-gray-600 text-sm h-[200px] flex justify-center items-center">no file selected</div>

                            <input type="file" name="file" accept="image/*" class="hidden" @change="fileChanged" ref="fileInput">
                            <InputError class="mt-2" :message="howToStepErrors.file" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4">
                            add
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import useAuth from "@/Composables/useAuth"
import InputLabel from "./InputLabel.vue";
import TextInput from "./TextInput.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { ref } from "vue";
import Modal from "./Modal.vue";
import useErrorHandler from "@/Composables/useErrorHandler";
import useModal from "@/Composables/useModal";
import TextBox from "./TextBox.vue";
import FilePreview from "./FilePreview.vue";
import { unref } from "vue";


const { goToLogin } = useAuth()
const { showModal, modalData } = useModal()
const { clearErrorData } = useErrorHandler()

const emits = defineEmits(['close', 'created'])

const props = defineProps({
    show: {
        default: false
    },
    positions: {
        default: []
    },
    elementIds: {
        default: []
    },
})

const howToStepData = ref({
    name: '',
    description: '',
    position: '',
    elementId: '',
    file: null,
})
const howToStepErrors = ref({
    name: '',
    description: '',
    position: '',
    elementId: '',
    file: '',
})
const fileInput = ref(null)

function closeModal() {
    clearHowToStepData()
    emits('close')
}

function fileChanged(e) {
    if (e.target.files?.length)
        howToStepData.value.file = e.target.files[0]

    fileInput.value.value = ''
}
  
async function createHowToStep() {
    clearErrorData(howToStepErrors, ['name', 'position', 'file'])

    if (!howToStepData.value.name?.length) {
        howToStepErrors.value.name = 'name is required.'
        return
    }
    
    if (!howToStepData.value.position) {
        howToStepErrors.value.position = 'position is required.'
        return
    }
    
    if (props.positions.findIndex((position) => position == howToStepData.value.position) > -1) {
        howToStepErrors.value.position = 'the position has already been taken.'
        return
    }
    
    if (!howToStepData.value.elementId) {
        howToStepErrors.value.elementId = 'element id is required.'
        return
    }
    
    if (props.elementIds.findIndex((elementId) => elementId == howToStepData.value.elementId) > -1) {
        howToStepErrors.value.elementId = 'the element id has already been taken.'
        return
    }
    
    // if (!howToStepData.value.file) {
    //     howToStepErrors.value.file = 'a file is required'
    //     return
    // }
    
    emits('created', {...howToStepData.value})
    closeModal()
}
 
function clearHowToStepData() {
    howToStepData.value.name = ''
    howToStepData.value.page = ''
    howToStepData.value.description = ''
    howToStepData.value.elementId = ''
    howToStepData.value.file = null
    howToStepData.value.position = 0
}

</script>