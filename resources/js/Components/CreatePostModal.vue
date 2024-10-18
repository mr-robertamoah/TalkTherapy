<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Create Post</div>
                <hr>
            </div>
            
            <div>
                <form 
                    @submit.prevent="createPost"
                >
                    <div class="mx-auto w-[90%] px-4 py-2 h-[60vh] overflow-hidden overflow-y-auto">
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="content" value="Content" />

                            <TextBox
                                id="content"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="postData.content"
                                rows="5"
                            />

                            <InputError class="mt-2" :message="postErrors.content" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="files" value="Images" />

                            <div class="flex justify-end items-center w-full mb-4 mt-2">
                                <PrimaryButton
                                    @click.prevent="() => fileInput.click()"
                                >add images</PrimaryButton>
                            </div>

                            <div v-if="postData.files?.length" class="p-2 flex justify-start items-center space-x-3 w-full mb-4 mt-2 overflow-hidden overflow-x-auto">
                                <FilePreview
                                    v-for="(file, idx) in postData.files"
                                    :file="file"
                                    :key="idx"
                                    class="h-[200px] w-[200px] shrink-0"
                                    :show-remove="true"
                                    @remove-file="() => removeFile(idx)"
                                />
                            </div>
                            
                            <div v-else class="text-center text-gray-600 text-sm h-[200px] flex justify-center items-center">no image selected</div>

                            <input type="file" name="file" accept="image/*" class="hidden" multiple @change="fileChanged" ref="fileInput">
                            <InputError class="mt-2" :message="postErrors.files" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4">
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

<script setup>
import InputLabel from "./InputLabel.vue";
import Alert from "./Alert.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { ref } from "vue";
import Modal from "./Modal.vue";
import useErrorHandler from "@/Composables/useErrorHandler";
import useModal from "@/Composables/useModal";
import TextBox from "./TextBox.vue";
import FilePreview from "./FilePreview.vue";
import useAlert from "@/Composables/useAlert";


const { showModal, modalData } = useModal()
const { clearErrorData } = useErrorHandler()
const { setFailedAlertData, alertData, clearAlertData } = useAlert()

const emits = defineEmits(['close', 'created'])

const props = defineProps({
    show: {
        default: false
    },
})

const postData = ref({
    content: '',
    files: [],
})
const postErrors = ref({
    content: '',
    files: '',
})
const fileInput = ref(null)

function closeModal() {
    clearData()
    emits('close')
}

function fileChanged(e) {
    if (e.target.files?.length)
        postData.value.files = [...e.target.files, ...(postData.value.files ?? [])]

    fileInput.value.value = ''
}

function removeFile(idx) {
    postData.value.files?.splice(idx, 1)
}
  
async function createPost() {
    clearErrorData(postErrors, ['content', 'files'])

    if (!postData.value.content?.length && !postData.value.files?.length) {
        if (!postData.value.content?.length)
            postErrors.value.content = 'add a content'

        if (!postData.value.files?.length)
            postErrors.value.files = 'add a file'

        setFailedAlertData({
            message: "You need to add content or at least one file.",
        })
        return
    }
    
    emits('created', {...postData.value})
    closeModal()
}
 
function clearData() {
    postData.value.content = ''
    postData.value.files = []
}

</script>