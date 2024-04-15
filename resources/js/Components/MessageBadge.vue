<template>
    <div
        class="w-full select-none"
        :class="{'border-blue-600 border rounded': currentReply, 'hidden': !show}"
        v-bind="$attrs"
        @dblclick="() => {
            if (!allowActions) return
            clickedActions()
        }"
    >
        <div 
            class="rounded-md w-[80%] xs:w-[70%] md:[60%] p-2 my-2 mx-1 shadow-sm"
            :class="[
                reply ? 'mx-auto' : (computedLeft ? 'ml-auto' : 'mr-auto'),
                reply 
                    ? 'bg-blue-300 border-2 border-blue-700' 
                    : (['deleted for everyone', 'deleted for me'].includes(status) ? 'bg-gray-300' : 'bg-white')
            ]"
        >
            <div>
                <div v-if="computedUppertext && !reply" 
                    class="text-xs font-bold text-teal-600"
                    :class="{'text-end': computedUppertext == 'You'}"
                >{{ computedUppertext }}</div>
                <div class="" v-if="msg.replying  && !reply">
                    <MessageBadge
                        :msg="msg.replying"
                        :allow-actions="false"
                        :allow-details="false"
                        :reply="true"
                    />
                </div>
                <hr class="my-2 text-blue-800" v-if="msg.replying && !reply">
                <div v-if="msg.content">{{ msg.content }}</div>
            </div>
            <div v-if="msg.files?.length" class="w-[90%] mx-auto my-2">
                <div class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 space-x-2">
                    <FilePreview
                        v-for="(file, idx) in msg.files"
                        :key="idx"
                        :file="file"
                        class="h-[90px] w-[90px] cursor-pointer"
                        :show-remove="false"
                        
                        @click="() => {
                            if (!id) return
                            showFileModal = true
                            currentFileIdx = idx
                        }"
                    />
                </div>
            </div>
            <div class="flex justify-end items-center" v-if="!reply">
                <div 
                    class="text-xs text-end my-1 lowercase"
                    :class="[['sending', 'retrying', 'updating'].includes(status) ? 'text-green-700' : (['failed', 'deleting', 'deleting for me'].includes(status) ? 'text-red-700 cursor-pointer' : 'text-gray-600')]"
                    v-if="status"
                    @click="() => {
                        if (status == 'failed' && !msg.id) createMessage()
                        if (status == 'failed' && msg.id) updateMessage()
                    }"
                >{{ ['sending', 'retrying'].includes(status) ? `${status}...` : status }}</div>
                <div 
                    class="text-xs text-end my-1 lowercase text-gray-600 ml-2"
                    v-if="msg.updatedAt"
                >{{ computedUpdatedAt }}</div>
            </div>
        </div>
    </div>

    <Modal
        :show="modalData.show && ['message'].includes(modalData.type)"
        @close="closeModal"
    >
        <div class="p-4 select-none">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Message for {{ msg.forType }}</div>
                <hr>
            </div>

            <div class="p-2 pb-4 h-[70vh] overflow-hidden overflow-y-auto">

                <div class="p-2 mt-2 mb-4 flex flex-col justify-center items-start bg-gray-200 rounded">
                    <div class="text-sm text-gray-600 mt-1">Message</div>
                    <div v-if="msg.content">
                        {{ msg.content }}
                    </div>
                    <div v-if="msg.files?.length">
                        showfiles
                    </div>
                </div>
                <div class="p-2 mt-2 mb-4 flex justify-center items-start bg-gray-200 rounded overflow-hidden overflow-x-auto space-x-3">

                    <div v-if="msg.replying">
                        <div class="text-sm text-gray-600 mt-1">Message replies</div>
                        <div v-if="msg.replying.content">
                            {{ msg.replying.content }}
                        </div>
                        <div v-if="msg.replying.files?.length">
                            showfiles
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600 mt-1">Replies to message</div>
                        <div class="flex flex-col overflow-hidden overflow-y-auto p-2">
                            <MessageBadge 
                                :item="item"
                                v-for="(m, idx) in replies.data"
                                :key="idx"
                                :allow-details="false"
                                :msg="m" 
                            />
                            <div class="font-bold p-2 cursor-pointer w-full flex justify-center items-center" v-if="replies.page && !getting">
                                <div
                                    @click="getMessageReplies" 
                                    class="cursor-pointer">...</div>
                            </div>
                            <div class="w-full text-green-600 text-sm my-2 text-center tracking-wide" v-if="getting">getting replies...</div>
                            <div class="" v-if="!replies.data.length">no replies</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </Modal>

    <FileModal
        v-if="msg.files?.length"
        :has-download="!!id"
        :file="msg.files[currentFileIdx]"
        :show="showFileModal"
        :next="currentFileIdx + 1 !== msg.files.length"
        :previous="currentFileIdx - 1 !== -1"
        @closeModal="() => showFileModal = false"

        @clicked-next="() => {
            if (currentFileIdx + 1 == msg.files.length) return
            currentFileIdx += 1
        }"
        @clicked-previous="() => {
            if (currentFileIdx - 1 == -1) return
            currentFileIdx -= 1
        }"
    />

    <MiniModal
        :show="modalData.show && ['actions', 'delete'].includes(modalData.type)"
        @close="closeModal"
    >
        <div v-if="modalData.type == 'actions'">
            <div class="text-gray-600 text-center font-bold tracking-wide">Actions</div>
            <hr class="my-2">

            <div class="p-4 flex flex-col items-center justify-center mx-auto w-[80%] md:w-[65%]">
                <PrimaryButton v-if="allowDetails" class="mb-2 flex justify-center w-full" @click="() => showModal('message')">replies</PrimaryButton>
                <PrimaryButton class="mb-2 flex justify-center w-full" @click="() => {
                    emits('selectAsReply', msg)
                    closeModal()
                }">reply to message</PrimaryButton>
                <template v-if="msg.fromUserId == userId">
                    <PrimaryButton class="mb-2 flex justify-center w-full" @click="() => {
                        emits('selectForUpdate', msg)
                        closeModal()
                    }">update</PrimaryButton>
                    <DangerButton class="mb-2 flex justify-center w-full" @click="() => {
                        showModal('delete')
                    }">delete</DangerButton>
                </template>
                <DangerButton class="mb-2 flex justify-center w-full" @click="clickedDeleteForMe">delete for me</DangerButton>
            </div>
        </div>
            
        <div v-if="modalData.type == 'delete'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete Message
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                    Are you sure you want to delete this message?
                </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                <DangerButton @click="deleteMessage" class="shrink-0">delete</DangerButton>
            </div>
        </div>

    </MiniModal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import useAlert from "@/Composables/useAlert";
import useAuth from "@/Composables/useAuth"
import useModal from "@/Composables/useModal"
import { usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, ref, watchEffect } from "vue"
import Modal from "./Modal.vue";
import Alert from "./Alert.vue";
import MiniModal from "./MiniModal.vue";
import PrimaryButton from "./PrimaryButton.vue";
import DangerButton from "./DangerButton.vue";
import { formatDistance } from 'date-fns';
import FilePreview from "./FilePreview.vue";
import FileModal from "./FileModal.vue";


const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()
const { modalData, showModal, closeModal } = useModal()

const emits = defineEmits(['onSuccess', 'onDelete', 'onUpdate', 'selectAsReply', 'selectForUpdate'])

const props = defineProps({
    msg: {
        default: null,
    },
    allowDetails: {
        default: true,
    },
    allowActions: {
        default: true,
    },
    isParticipant: {
        default: true,
    },
    reply: {
        default: false,
    },
    currentReply: {
        default: false,
    },
    idx: {
        default: -1,
    },
    item: {
        default: null,
    },
    show: {
        default: true,
    },
})

const userId = usePage().props.auth.user?.id
const loading = ref(false)
const showFileModal = ref(false)
const getting = ref(false)
const currentFileIdx = ref(0)
const status = ref('')
const id = ref(null)
const replies = ref({ data: [], page: 1 })

onBeforeUnmount(() => {
    Echo.leave(`messages.${id.value}`)
})

watchEffect(() => {
    if (!props.msg?.id) return

    Echo
        .private(`messages.${props.msg?.id}`)
        .listen('.message.updated', (data) => {
            if (data.message?.fromUserId == userId)
                return
            emits('onUpdate', data.message)
        })
        .listen('.message.deleted', (data) => {
            console.log(data, 'message.deleted');
            if (data.message?.fromUserId == userId)
                return
            emits('onUpdate', data.message)
        })
})
watchEffect(() => {
    if ((props.msg?.files || props.msg?.content) && status.value == 'sending' && !loading.value)
        createMessage()
    
    if ((props.msg?.files || props.msg?.content) && status.value == 'updating' && !loading.value)
        updateMessage()
})
watchEffect(() => {
    if (props.msg?.status)
        status.value = props.msg.status
})
watchEffect(() => {
    if (props.msg?.id)
        id.value = props.msg.id
})
watchEffect(() => {
    if (modalData.value.type == 'message' && id.value) {
        getMessageReplies()
    }
})

const computedLeft = computed(() => {
    if (props.msg.fromUserId == userId) return true
    
    if (status.value == 'deleted for me') return false
    
    if (props.isParticipant && props.msg.fromUserId !== userId) return false

    if (!props.isParticipant && props.msg.fromCounsellor) return false

    if (!props.isParticipant && !props.msg.fromCounsellor) return true
    
    if (!props.msg.fromCounsellor) return true

    return false
})
const computedUppertext = computed(() => {
    if (
        (props.msg?.fromCounsellor && props.msg?.fromUserId == userId)
    ) return 'You'
    
    if (
        (props.msg?.fromCounsellor)
    ) return 'Counsellor'

    if (
        (props.isParticipant && props.msg?.fromUserId == userId)
    ) return 'You'

    return ''
})
const computedUpdatedAt = computed(() => {
    if (!props.msg.updatedAt) return ''

    return formatDistance(props.msg.updatedAt, new Date, { addSuffix: true })
})

function clickedActions() {
    if (!props.msg.id || status.value?.includes('deleted for')) return

    if (props.msg.fromUserId == userId || props.isParticipant) {
        showModal('actions')

        return
    }
        
    if (props.allowDetails) showModal('message')
}

async function updateMessage() {
    loading.value = true
    status.value = 'updating'
    
    await axios
        .post(route('api.messages.update', { messageId: props.msg.id }), {
            content: props.msg.content,
            files: props.msg.files,
            deletedFiles: props.msg.deletedFiles,
        }, {
            headers: {'Content-Type': 'multipart/form-data'},
        })
        .then((res) => {
            console.log(res)

            status.value = 'sent'
            emits('onUpdate', res.data.message)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            status.value = 'failed'
            setFailedAlertData({
                message: "Message was not sent. Clicked 'failed' to retry."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

async function deleteMessage() {
    closeModal()
    loading.value = true
    status.value = 'deleting'
    
    await axios
        .delete(route('api.messages.delete', { messageId: props.msg.id }))
        .then((res) => {
            console.log(res)

            status.value = res.data.message.status
            emits('onUpdate', res.data.message)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            status.value = 'sent'
            setFailedAlertData({
                message: "Message was not deleted. Try again in a short while."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

async function clickedDeleteForMe() {
    closeModal()
    loading.value = true
    status.value = 'deleting for me'
    
    await axios
        .delete(route('api.messages.delete.me', { messageId: props.msg.id }))
        .then((res) => {
            console.log(res)

            status.value = res.data.message.status
            emits('onUpdate', res.data.message)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            status.value = 'sent'
            setFailedAlertData({
                message: "Message was not deleted for you. Try again in a short while."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

async function createMessage() {
    
    loading.value = true
    if (status.value == 'failed') status.value = 'retrying'

    let data = {
            ...props.msg,
            confidential: props.msg.confidential ? 1 : 0,
            replyId: props.msg.replying?.id
        }
    delete data.fromCounsellor
    delete data.counsellorAvatar
    delete data.replying
    delete data.status
    delete data.fromUserId
    delete data.toUserId
    
    await axios
        .post(route('api.messages.create'), data, {
            headers: {'Content-Type': 'multipart/form-data'},
        })
        .then((res) => {
            console.log(res)

            status.value = 'sent'
            emits('onSuccess', res.data.message, props.idx)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            status.value = 'failed'
            setFailedAlertData({
                message: "Message was not sent. Clicked 'failed' to retry."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

function updatePage(res) {
    if (res.data.links.next) replies.value.page = replies.value.page + 1
    else replies.value.page = 0
}

async function getMessageReplies() {
    if (!id.value) return

    getting.value = true

    await axios
        .get(route('api.message.replies.get', {
            messageId: props.msg?.id,
            page: replies.value.page
        }))
        .then((res) => {
            console.log(res)
            if (replies.value.page == 1)
                replies.value.data = []
            
            replies.value.data = [
                ...replies.value.data,
                ...res.data.data,
            ]

            updatePage(res)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
        .finally(() => {
            getting.value = false
        })
}
</script>