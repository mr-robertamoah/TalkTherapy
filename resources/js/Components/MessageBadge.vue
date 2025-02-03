<template>
    <div
        class="w-full select-none"
        :class="{
            'border-blue-600 border rounded': currentReply, 
            'hidden': !show,
            '': currentTopic && msg.topicId == currentTopic.id
        }"
        v-bind="$attrs"
        @dblclick="replyToMessage"
        ref="item"
    >
        <div 
            class="w-full text-center p-2 bg-gray-600 text-white cursor-pointer"
            v-if="msg.name"
        >
            {{ msg.name }}
        </div>

        <div
            v-else
            class="rounded-md w-[90%] sm:w-[70%] p-2 my-2 mx-1 shadow-sm"
            :class="[
                currentTopic && msg.topicId == currentTopic?.id ? (computedLeft ? 'border-l-4 border-gray-600' : 'border-r-4 border-gray-600') : '',
                reply ? 'mx-auto p-0 text-xs text-center' : (computedLeft ? 'ml-auto' : 'mr-auto'),
                reply 
                    ? '' 
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
                <div
                    v-if="msg.content"
                    class=""
                    :class="[reply ? 'text-xs text-gray-400' : 'text-sm sm:text-base']"
                >{{ msg.content }}</div>
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
            <div class="flex justify-end items-center space-x-2" v-if="!reply">
                <div 
                    class="text-gray-600 text-xs"
                    v-if="msg.createdAt && msg.createdAt !== msg.updatedAt">edited .</div>
                <div 
                    class="text-xs text-end my-1 lowercase"
                    :class="[['sending', 'retrying', 'updating'].includes(status) ? 'text-green-700' : (['failed', 'deleting', 'deleting for me'].includes(status) ? 'text-red-700 cursor-pointer' : 'text-gray-600')]"
                    v-else-if="status"
                    @click="() => {
                        if (status == 'failed' && !msg.id) createMessage()
                        if (status == 'failed' && msg.id) updateMessage()
                    }"
                >{{ ['sending', 'retrying'].includes(status) ? `${status}...` : status }}</div>
                <div 
                    class="text-xs text-end my-1 lowercase text-gray-600"
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

            <div class="p-2 pb-4 h-[70vh] overflow-hidden overflow-y-auto w-full">

                <div class="p-2 mt-2 mb-4 flex flex-col justify-center items-start bg-gray-200 mx-auto rounded min-h-[200px] w-[90%]">
                    <div class="text-xs sm:text-sm text-gray-600 mt-1">Message</div>
                    <div>
                        <div v-if="msg.content">{{ msg.content }}</div>
                    </div>
                    <div v-if="msg.files?.length" class="w-[90%] mx-auto my-2">
                        <div class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 space-x-2">
                            <FilePreview
                                v-for="(file, idx) in msg.files"
                                :key="idx"
                                :file="file"
                                class="h-[300px] w-[300px] cursor-pointer"
                                :show-remove="false"
                                
                                @click="() => {
                                    if (!id) return
                                    showFileModal = true
                                    currentFileIdx = idx
                                }"
                            />
                        </div>
                    </div>
                </div>
                <div class="flex justify-start items-start overflow-hidden overflow-x-auto space-x-3 p-2">
                    <div class="p-2 mt-2 mb-4 bg-gray-200 mx-auto rounded min-h-[200px] w-[90%]">
                        <div class="text-xs sm:text-sm text-gray-600 mt-1">Replying:</div>
                        <div v-if="msg.replying">
                            <div v-if="msg.replying.content">
                                {{ msg.replying.content }}
                            </div>
                            <div v-if="msg.replying.files?.length" class="w-[90%] mx-auto my-2">
                                <div class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 space-x-2">
                                    <FilePreview
                                        v-for="(file, idx) in msg.files"
                                        :key="idx"
                                        :file="file"
                                        class="h-[300px] w-[300px] cursor-pointer"
                                        :show-remove="false"
                                        
                                        @click="() => {
                                            if (!id) return
                                            showFileModal = true
                                            currentFileIdx = idx
                                        }"
                                    />
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-xs sm:text-sm text-center text-gray-600 font-bold my-6">this message replies to non</div>
                    </div>
                    <div class="p-2 mt-2 mb-4 bg-gray-200 mx-auto rounded min-h-[200px] w-[90%]">
                        <div class="text-xs sm:text-sm text-gray-600 mt-1">Replies to message:</div>
                        <div class="flex flex-col overflow-hidden overflow-y-auto p-2">
                            <template v-if="replies.data?.length">
                                <MessageBadge 
                                    :item="item"
                                    v-for="(m, idx) in replies.data"
                                    :key="idx"
                                    :allow-details="false"
                                    :allow-actions="false"
                                    :msg="m" 
                                />
                            </template>
                            <div class="font-bold p-2 cursor-pointer w-full flex justify-center items-center" v-if="replies.page && !getting">
                                <div
                                    @click="getMessageReplies" 
                                    class="cursor-pointer">...</div>
                            </div>
                            <div class="w-full text-green-600 text-xs sm:text-sm my-2 text-center tracking-wide" v-if="getting">getting replies...</div>
                            <div class="text-xs sm:text-sm text-center text-gray-600 font-bold my-4" v-if="!replies.data.length">this message has no replies</div>
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

            <div class="p-4 flex flex-col items-center justify-center mx-auto space-y-3 w-[80%] md:w-[65%]">
                <PrimaryButton v-if="allowDetails" class="w-fit" @click="() => showModal('message')">view replies</PrimaryButton>
                <PrimaryButton class="w-fit" @click="() => {
                    emits('selectAsReply', msg)
                    closeModal()
                }">reply to message</PrimaryButton>
                <template v-if="msg.fromUserId == userId">
                    <PrimaryButton class="w-fit" @click="() => {
                        emits('selectForUpdate', msg)
                        closeModal()
                    }">update</PrimaryButton>
                    <DangerButton class="w-fit" @click="() => {
                        showModal('delete')
                    }">delete</DangerButton>
                </template>
                <DangerButton class="w-fit" @click="clickedDeleteForMe">delete for me</DangerButton>
            </div>
        </div>
            
        <div v-if="modalData.type == 'delete'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete Message
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="my-4 text-xs sm:text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
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
import { computed, onBeforeUnmount, ref, watchEffect, nextTick, watch } from "vue"
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

const emits = defineEmits(['onSuccess', 'onDelete', 'onUpdate', 'selectAsReply', 'selectForUpdate', 'unsetTopic'])

const props = defineProps({
    msg: {
        default: null,
    },
    currentTopic: {
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
const listening = ref(false)
const showFileModal = ref(false)
const getting = ref(false)
const currentFileIdx = ref(0)
const status = ref('')
const item = ref(null)
const id = ref(null)
const replies = ref({ data: [], page: 1 })

onBeforeUnmount(() => {
    Echo.leave(`messages.${id.value}`)
})

watchEffect(() => {
    const user = usePage().props.auth.user

    if (!props.msg?.id || !user?.id || props.msg?.name) return

    if (listening.value) return
    listening.value = true

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
    if (props.msg?.name) return

    if ((props.msg?.files || props.msg?.content) && status.value == 'sending' && !loading.value)
        createMessage()
    
    if ((props.msg?.files || props.msg?.content) && status.value == 'updating' && !loading.value)
        updateMessage()
})
watchEffect(() => {
    if (props.msg?.name) return
    
    if (props.msg?.status)
        status.value = props.msg.status
})
watchEffect(() => {
    if (props.msg?.scroll)
        scrollToItem()
})
watchEffect(() => {
    if (props.msg?.name) return
    
    if (props.msg?.id)
        id.value = props.msg.id
})
watchEffect(() => {
    if (modalData.value.type == 'message' && id.value) {
        getMessageReplies()
    }
})

const computedLeft = computed(() => {
    if (props.msg?.name) return false
    
    if (props.msg.fromUserId == userId) return true
    
    if (status.value == 'deleted for me') return false
    
    if (props.isParticipant && props.msg.fromUserId !== userId) return false

    if (!props.isParticipant && props.msg.fromCounsellor) return false

    if (!props.isParticipant && !props.msg.fromCounsellor) return true
    
    if (!props.msg.fromCounsellor) return true

    return false
})
const computedUppertext = computed(() => {
    if (props.msg?.name) return ''

    if (
        (props.msg?.fromCounsellor && props.msg?.fromUserId == userId)
    ) return 'You'

    if (props.msg?.forType == 'Discussion' && props.msg?.fromUserId !== userId)
        return props.msg?.counsellorName

    if (props.msg?.forType == 'Discussion' && props.msg?.fromUserId == userId)
        return 'You'
    
    if (
        (props.msg?.fromCounsellor)
    ) return 'Counsellor'

    if (
        (props.isParticipant && props.msg?.fromUserId == userId)
    ) return 'You'

    return ''
})
const computedUpdatedAt = computed(() => {
    if (props.msg?.name) return ''
    
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

function replyToMessage() {
    if (props.msg.name && !props.msg.end) return emits('unsetTopic')
    
    if (!props.allowActions) return

    clickedActions()
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

async function scrollToItem() {
    await nextTick()

    if (item.value)
        item.value.scrollIntoView()
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
            scrollToItem()
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            status.value = 'failed'
            
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 4000,
                })
                return
            }

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