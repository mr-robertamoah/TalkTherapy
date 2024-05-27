<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit capitalize mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >{{ discussion.name }}</div>
                <hr>
            </div>
            <div class="flex justify-end my-2 w-[90%] relative">
                <OptionIcon
                    @click="() => showOptions = !showOptions"
                    title="copy"
                    class="cursor-pointer w-5 h-5"/>

                <div v-if="showOptions" class="absolute w-fit p-2 rounded bg-gray-600 text-gray-600 text-sm">
                    <div
                        v-for="(opt, idx) in options.filter((o) => o !== view)"
                        :key="idx"
                        class="bg-white my-2 p-2 w-20 cursor-pointer rounded text-center"
                        @click="() => {
                            showOptions = false
                            if (opt == 'close') {
                                return
                            }

                            view = opt
                        }"
                    >{{ opt }}</div>
                </div>
            </div>
            <div v-if="view == 'main'" class="overflow-hidden overflow-y-auto h-[70vh] w-[90%] mx-auto md:w-[70%]">
                <div v-if="discussion.description" class="p-4 rounded bg-gray-200 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">description</div>
                    <div class="mt-2 text-sm text-gray-600 text-justify">{{ discussion.description }}</div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">sessions</div>
                    
                    <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="discussion.session">
                            <PreferenceItem
                                :item="discussion.session"
                                :show-actions="false"
                            />
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no session</div>
                    </div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">dates</div>
                    <div>
                        <div class="text-gray-600 text-sm tracking-wide flex justify-center items-center flex-col space-y-3 py-2">
                            <div class="">{{ 
                                discussion.status == 'PENDING' 
                                    ? 'Is to start on '
                                    : (discussion.status == 'FAILED' ? 'Was to start on ' : 'Started on')
                            }} <span class="text-gray-700 font-bold">{{ (new Date(discussion.startTime)).toGMTString() }}</span></div>
                            <div class="">{{ 
                                ['PENDING', 'IN_SESSION_CONFIRMATION', 'IN_SESSION'].includes(discussion.status)
                                    ? 'Is to end on '
                                    : (['FAILED', 'ABANDONED'].includes(discussion.status) ? 'Was to end on ' : 'Ended on')
                            }} <span class="text-gray-700 font-bold">{{ (new Date(discussion.endTime)).toGMTString() }}</span></div>
                        </div>
                        <div class="text-end text-gray-600 text-sm my-2">created {{ discussion.createdAt }}</div>
                    </div>
                </div>
            </div>
            <div v-if="view == 'counsellors'" class="overflow-hidden overflow-y-auto h-[70vh] w-[90%] mx-auto md:w-[70%]">
                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Counsellor Search</div>
                    
                    <div v-if="getting.show && getting.type == 'counsellors'" class="text-center text-sm w-full my-4 text-green-600 bg-green-200">getting counsellors</div>
                    <div class="p-2 flex justify-start space-x-3 items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="counsellors.data?.length">
                            <CounsellorComponent
                                v-for="counsellor in counsellors.data"
                                :counsellor="counsellor"
                                :key="counsellor.id"
                                class="w-[70%] shrink-0"
                            />

                            <div
                                title="get more counsellors"
                                @click="getCounsellors"
                                v-if="counsellors.page"
                                class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no counsellors searched</div>
                    </div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Discussion Counsellors</div>
                    
                    <div v-if="getting.show && getting.type == 'discussion'" class="text-center text-sm w-full my-4 text-green-600 bg-green-200">getting counsellors</div>
                    <div class="p-2 flex justify-start space-x-3 items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="discussionCounsellors.data?.length">
                            <CounsellorComponent
                                v-for="counsellor in discussionCounsellors.data"
                                :counsellor="counsellor"
                                :key="counsellor.id"
                                class="w-[70%] shrink-0"
                            />

                            <div
                                title="get more counsellors"
                                @click="getDiscussionCounsellors"
                                v-if="counsellors.page"
                                class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no counsellors searched</div>
                    </div>
                </div>
            </div>
            <div v-if="view == 'messages'" class="overflow-hidden overflow-y-auto h-[70vh] w-[90%] mx-auto md:w-[70%]">
                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Messages</div>
                    
                    <div v-if="getting.show && getting.type == 'messages'" class="text-center text-sm w-full my-4 text-green-600 bg-green-200">getting messages...</div>
                    <div class="p-2 flex flex-col justify-start space-y-3 items-center overflow-hidden h-[50vh] overflow-y-auto my-2">
                                
                        <template v-if="messages.data?.length">
                        <div
                                title="get more messages"
                                @click="getMessages"
                                v-if="messages.page"
                                class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                            <MessageBadge
                                v-for="message in messages.data"
                                :msg="message"
                                :key="message.id"
                                class="w-[70%] shrink-0"
                            />
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no messages</div>
                    </div>
                    
                    <div class="rounded-lg bg-stone-100 w-full p-2" v-if="computedCanSendMessage && !getting.show">
                        <div v-if="message.replying" class="relative">
                            <MessageBadge
                                :msg="message.replying"
                                :allow-actions="false"
                                :allow-details="false"
                                :reply="true"
                            />
                            <div 
                                @click="removeReply"
                                class="-top-2 absolute bg-blue-600 cursor-pointer flex h-5 items-center justify-center p-2 rounded-full text-blue-200 text-xs w-5 z-[1]">
                                <div>X</div>
                            </div>
                        </div>
                        <div v-if="message.id" class="text-sm text-gray-600 my-2 text-center">updating message</div>
                        <div class="w-[90%] mx-auto min-h-10 flex justify-center items-start space-x-2">
                            <TextBox
                                rows="3"
                                class="w-full shrink"
                                v-model="message.content"
                            />
                            <div class="flex justify-end space-x-2 items-start">
                                <PaperplaneIcon v-if="computedHasMessage" @click="sendMessage" class="w-8 cursor-pointer p-1 h-8 rotate-45" />
                                <PaperclipIcon class="w-8 cursor-pointer p-1 h-8"
                                    @click="() => showAttachmentIcons = true"
                                />
                            </div>
                        </div>
                        <div class="w-full max-h-[100px] p-2 flex justify-start overflow-hidden overflow-x-auto items-center space-x-2" v-if="files?.length">
                            <FilePreview
                                v-for="(file, idx) in files"
                                :key="idx"
                                :file="file"
                                class="h-[90px] w-[90px]"
                                @remove-file="() => removeUploadFile(file, idx)"
                            />
                        </div>
                    </div>
                    <div
                        @click.self="() => showAttachmentIcons = false"
                        :class="[showAttachmentIcons ? 'opacity-100 visible z-[1]' : 'opacity-0 invisible -z-[1]']" 
                        class="w-full top-0 absolute transition-all duration-100 right-0 h-full bg-gray-600 bg-opacity-30 flex justify-center items-center">
                        <div class="w-[80%] bg-white min-h-32 rounded flex justify-center items-center space-x-2 flex-wrap">
                            <CameraIcon title="take a picture" @click="() => clickedIcon('camera')" class="w-8 cursor-pointer p-1 h-8 flex justify-center items-center" />
                            <MicrophoneIcon title="record your voice note" @click="() => clickedIcon('microphone')" class="w-8 cursor-pointer p-1 h-8" />
                            <FileIcon title="upload an image or pdf file" @click="() => clickedIcon('file')" class="w-8 cursor-pointer p-1 h-8" />
                        </div>
                    </div>
                </div>
            </div>

            <input 
                type="file"
                name="messageFiles"
                ref="messageFilesInput"
                @change="changeFile" 
                class="hidden" id="messageFiles" multiple accept="image/*">
        </div>
    </Modal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />

    <MediaCapture
        :show="mediaCaptureData.show"
        :type="mediaCaptureData.type"
        @close="closeMediaCapture"
        @send-file="(file) => {
            files = [file, ...files]
        }"
    />
</template>

<script setup>
import useAlert from '@/Composables/useAlert';
import { ref, watchEffect, watch, computed } from 'vue';
import Modal from './Modal.vue';
import PreferenceItem from './PreferenceItem.vue';
import useAuth from '@/Composables/useAuth';
import OptionIcon from '@/Icons/OptionIcon.vue';
import Alert from './Alert.vue';
import CounsellorComponent from './CounsellorComponent.vue';
import MessageBadge from './MessageBadge.vue';
import CameraIcon from '@/Icons/CameraIcon.vue';
import MicrophoneIcon from '@/Icons/MicrophoneIcon.vue';
import FileIcon from '@/Icons/FileIcon.vue';
import PaperplaneIcon from '@/Icons/PaperplaneIcon.vue';
import PaperclipIcon from '@/Icons/PaperclipIcon.vue';
import FilePreview from './FilePreview.vue';
import { usePage } from '@inertiajs/vue3';
import MediaCapture from './MediaCapture.vue';
import useMessage from '@/Composables/useMessage';


const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()
const { goToLogin } = useAuth()
const {
    message, files, deletedFiles, computedHasMessage, replyingMessage, scrollToBottom,
    showAttachmentIcons, messageFilesInput, changeFile, resetMessage, updateMessage,
    clickedIcon, mediaCaptureData, closeMediaCapture, removeUploadFile, scrollToMessageId,
    selectForUpdate, selectAsReply, removeReply
} = useMessage()

const emits = defineEmits(['close'])

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    discussion: {
        default: null
    }
})

const getting = ref({ show: false, type: '' })
const discussionCounsellors = ref({ data: [], page: 1 })
const sessionMessages = ref({ data: [], page: 1 })
const messages = ref({ data: [], page: 1 })
const counsellors = ref({ data: [], page: 1 })
const counsellorSearch = ref('')
const showOptions = ref(false)
const view = ref('main')
const options = ref(['main', 'counsellors', 'messages', 'close'])

watch(() => props.show, () => {
    if (!props.show) return

    if (!counsellors.value?.data?.length && !counsellors.value?.page) 
        debouncedGetCounsellors()

    if (!discussionCounsellors.value?.data?.length) {
        discussionCounsellors.value.page = 1
        getDiscussionCounsellors()
    }
})

const computedCanSendMessage = computed(() => {
    if (['FAILED', 'ABANDONED', 'HELD', 'HELD_CONFIRMATION', 'PENDING', 'IN_SESSION_CONFIRMATION'].includes(props.discussion?.status)) return false

    const user = usePage().props.auth.user

    if (props.discussion.addedby.userId == user.id) return true

    if (discussionCounsellors.value.data.filter((c) => c.userId == user.id).length) return true

    return false
})

const debouncedGetCounsellors = _.debounce(() => {
    counsellors.value.page = 1
    getCounsellors()
}, 500)

async function getCounsellors() {
    if (!counsellors.value.page) return

    setGetting('counsellors')
    await axios.get(route('api.counsellors',{
        page: counsellors.value.page,
        name: counsellorSearch.value
    }))
        .then((res) => {
            console.log(res)
            if (counsellors.value.page == 1)
                counsellors.value.data = []
            
            counsellors.value.data = [
                ...counsellors.value.data,
                ...res.data.data,
            ]

            updatePage(res, counsellors)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

function updatePage(res, data) {
    if (res.data.links.next) data.value.page = data.value.page + 1
    else data.value.page = 0
}

async function getMessages() {
    if (!messages.value.page) return

    setGetting('messages')
    await axios.get(route('api.messages',{
        page: messages.value.page,
    }))
        .then((res) => {
            console.log(res)
            if (messages.value.page == 1)
                messages.value.data = []
            
            messages.value.data = [
                ...messages.value.data,
                ...res.data.data,
            ]

            updatePage(res, messages)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

async function getDiscussionCounsellors() {
    if (!discussionCounsellors.value.page) return

    setGetting('discussion')

    await axios.get(route(`api.discussions.counsellors`, {
        page: discussionCounsellors.value.page, 
        discussionId: props.discussion.id,
    }))
    .then((res) => {
        console.log(res)
        
        if (discussionCounsellors.value.page == 1)
            discussionCounsellors.value.data = []
        
        discussionCounsellors.value.data = [
            ...discussionCounsellors.value.data,
            ...res.data.data,
        ]

        updatePage(res, discussionCounsellors)
    })
    .catch((err) => {
        console.log(err)
        goToLogin(err)

        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
                time: 10000
            })
            return
        }

        if (err.alert) {
            setFailedAlertData({
                message: err.alert,
                time: 5000
            })
            return
        }

        setFailedAlertData({
            message: 'Something unfortunate happened. Please try again later.',
            time: 5000
        })
    
    })

    clearGetting()
}

function replaceOldMessage(data) {
    let itemsRef = messages.sessions[selectedSession.value?.id]
    if (computedCurrentFilter.value == 'topic') {
        itemsRef = messages.topics[selectedTopicSession.value?.id]
    }

    itemsRef.data.splice(itemsRef.data.findIndex((d) => d.id == data.id), 1, {...data})
    chatMessages.value.splice(chatMessages.value.findIndex((d) => d.id == data.id), 1, {...data})
}

function replaceFirstMessage(data) {
    let itemsRef = messages.sessions[selectedSession.value?.id]
    if (computedCurrentFilter.value == 'topic') {
        itemsRef = messages.topics[selectedTopicSession.value?.id]
    }

    if (itemsRef.data.length)
        itemsRef.data[0] = {...data}

    chatMessages.value[chatMessages.value.length - 1] = {...data}
}

function onMessageCreated(data) {
    addNewMessage(data)
}

function addNewMessage(newMessage) {
    let item
    if (!newMessage) return

    if (computedCurrentFilter.value == 'session') {

        messages.sessions[selectedSession.value.id].data = [
            {...newMessage},
            ...messages.sessions[selectedSession.value.id].data,
        ]

        item = messages.sessions[selectedSession.value.id]
    }

    if (item)
        chatMessages.value = [
            ...item.data.toReversed()
        ]
    
    scrollToBottom()
}

async function getSessionMessages() {
    if (getting.value.show || !props.discussion?.session?.id || !sessionMessages.value.page) return

    setGetting('session messages')

    await axios
        .get(route('api.session.messages.get', {
            sessionId: props.discussion?.session?.id,
            page: sessionMessages.value.page
        }))
        .then(async (res) => {
            console.log(res)

            if (sessionMessages.value.page == 1)
                sessionMessages.value.data = []

            sessionMessages.value.data = [
                ...sessionMessages.value.data,
                ...res.data.data,
            ]
            
            if (sessionMessages.value.page == 1)
                scrollToBottom()
            else
                scrollToMessageId(`message_${res.data.data.length - 1}`)

            updatePage(res, sessionMessages)
        })
        .catch((err) => {
            console.log(err)
            if (err?.response?.status == 429) {
                selectedSession.value = null
                setFailedAlertData({
                    message: 'You have made too many requests within a short period. Try again shortly.',
                    time: 5000
                })
                return
            }

            goToLogin(err)
        })
        .finally(() => {
            clearGetting()
        })
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
}

function closeModal() {
    emits('close')
}
</script>