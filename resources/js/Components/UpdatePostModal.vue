<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Post</div>
                <hr>
            </div>
            
            <div>
                <form 
                    @submit.prevent="updatePost"
                >
                    <div class="mx-auto w-[90%] px-4 py-2 h-[60vh] overflow-hidden overflow-y-auto">
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="description" value="Content" />

                            <TextBox
                                id="description"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="postData.content"
                                rows="5"
                            />

                            <InputError class="mt-2" :message="postErrors.content" />
                        </div>
                        
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="file" value="Images" />

                            <div class="flex justify-end items-center w-full space-x-3 mb-4 mt-2">
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
                                    @remove-file="() => removeFile(file, idx)"
                                />
                            </div>
                            
                            <div v-else class="text-center text-gray-600 text-sm h-[200px] flex justify-center items-center">no image selected</div>

                            <div>
                                <div class="text-center text-gray-600 text-sm flex justify-center items-center">deleted images</div>

                                <div v-if="postData.deletedFiles?.length" class="p-2 bg-red-300 flex justify-start items-center space-x-3 w-full mb-4 mt-2 overflow-hidden overflow-x-auto">
                                    <FilePreview
                                        v-for="(file, idx) in postData.deletedFiles"
                                        :file="file"
                                        :key="idx"
                                        class="h-[200px] w-[200px] shrink-0"
                                        :show-remove="true"
                                        @remove-file="() => restoreFile(file, idx)"
                                    />
                                </div>

                                <div v-else class="text-center text-gray-600 text-sm h-[200px] flex justify-center items-center">no image deleted</div>
                            </div>

                            <input type="file" name="file" accept="image/*" class="hidden" multiple @change="fileChanged" ref="fileInput">
                            <InputError class="mt-2" :message="postErrors.files" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4">
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
import useAuth from "@/Composables/useAuth"
import InputLabel from "./InputLabel.vue";
import InputError from "./InputError.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { ref, watch } from "vue";
import Modal from "./Modal.vue";
import useErrorHandler from "@/Composables/useErrorHandler";
import useModal from "@/Composables/useModal";
import TextBox from "./TextBox.vue";
import FilePreview from "./FilePreview.vue";
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";


const { goToLogin } = useAuth()
const { showModal, modalData } = useModal()
const { clearErrorData } = useErrorHandler()
const { setFailedAlertData, alertData, clearAlertData } = useAlert()

const emits = defineEmits(['close', 'updated'])

const props = defineProps({
    show: {
        default: false
    },
    post: {
        default: null
    },
})

const postData = ref({
    content: '',
    files: [],
    deletedFiles: [],
})
const postErrors = ref({
    content: '',
    files: '',
    deletedFiles: '',
})
const fileInput = ref(null)

watch(() => props.show, () => {
    if (!props.show) return

    setPostData()
})

function removeFile(file, idx) {
    postData.value.files?.splice(idx, 1)

    if (file.id)
        postData.value.deletedFiles = [file, ...postData.value.deletedFiles]
}

function restoreFile(file, idx) {
    postData.value.deletedFiles?.splice(idx, 1)
    postData.value.files = [file, ...postData.value.files]
}

function setPostData() {
    postData.value.content = props.post.content
    postData.value.files = [...(props.post.files ?? [])]
    postData.value.deletedFiles = []
}

function closeModal() {
    clearPostData()
    emits('close')
}

function fileChanged(e) {
    if (e.target.files?.length)
        postData.value.files = [...e.target.files, ...(postData.value.files ?? [])]

    fileInput.value.value = ''
}
  
async function updatePost() {
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
    
    emits('updated', {...props.post, ...postData.value,  status: 'updating'})
    closeModal()
}
 
function clearPostData() {
    postData.value.content = ''
    postData.value.files = []
    postData.value.deletedFiles = []
}

</script>