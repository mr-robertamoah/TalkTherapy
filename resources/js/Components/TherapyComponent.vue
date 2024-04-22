<template>
    <div
        class="relative w-full"
    >
        <PrimaryButton
            class="my-2"
            @click="clickedSwitchToActiveSession"
            v-if="selectedSession?.id && activeSession?.id && selectedSession?.id !== activeSession?.id"
        >switch to active session</PrimaryButton>
        <div class="text-gray-600 font-bold capitalize">Filter By</div>
    
        <div class="mt-4">
            <label class="flex items-center mb-2">
                <Checkbox v-model:checked="filters.sessions"></Checkbox>
                <span class="text-gray-600 ml-2">Sessions</span>
            </label>
            <label class="flex items-center">
                <Checkbox v-model:checked="filters.topics"></Checkbox>
                <span class="text-gray-600 ml-2">Topics</span>
            </label>
        </div>
        <FormLoader class="mx-auto relative" :show="loading" :text="`getting ${computedCurrentFilter}s`"/>
    </div>
    <div class="my-2 w-full h-1 rounded bg-stone-400"></div>
    <div v-bind="$attrs" class="min-h-[500px] relative flex flex-col items-center justify-center">
        <div v-if="therapy.status == 'PENDING'"
            class="flex absolute top-0 left-0 w-full h-full bg-gray-500 justify-center items-center text-gray-600 text-sm bg-opacity-35 rounded z-[1]">
            therapy is still pending
        </div>
        <div 
            class="rounded-lg bg-stone-100 w-full min-h-[50px] p-2 space-x-3 flex justify-start items-center overflow-hidden overflow-x-auto mb-2 transition duration-200"
            :class="[computedFiltered ? 'opacity-100 translate-y-0' : 'opacity-20 -translate-y-5']"
            v-if="computedFiltered"
        >
            <PrimaryButton class="shrink-0" @click="clickedCreateTopic">create topic</PrimaryButton>
            <template v-if="computedFilteredItems.length">
                <TherapyFilterItem
                    v-for="(item, idx) in computedFilteredItems"
                    :key="idx"
                    :item="item"
                    :therapy="therapy"
                    :type="computedCurrentFilter"
                    :loaded-sessions="sessions"
                    :loaded-sessions-page="pages.session"
                    :loaded-topics="topics"
                    :loaded-topics-page="pages.topic"
                    :is-active="item.id == activeSession?.id && computedCurrentFilter == 'session'"
                    class="w-[60%] shrink-0"
                    @dblclick="() => clickedFilterItem(item)"
                    @on-update="(data) => onUpdateItem(data)"
                    @on-delete="(data) => onDeleteItem(data)"
                    @on-message-created="(data) => onMessageCreated(data)"
                />
            </template>
            <div v-if="computedFilteredItems.length && computedPages && !loading">
                <div
                    title="get more"
                    @click="clickedGetMore"
                    class="text-gray-600 text-lg cursor-pointer p-2 align-middle hover:border-gray-600 border rounded-sm border-transparent w-fit h-[30px] flex justify-center items-center">
                    <div>...</div>
                </div>
            </div>
            <div v-if="!computedFilteredItems.length && !computedPages" class="w-full shrink">
                <div
                    class="text-gray-600 text-sm p-2 align-middle w-full text-center">
                    <div>no {{ computedCurrentFilter }}s</div>
                </div>
            </div>
        </div>
        <div class="rounded-lg min-h-[400px] bg-stone-200 h-full w-full shrink mb-2">
            <div 
                :title="`double click to deselect.`"
                @click.self="deselectItem"
                @dblclick="deselectItem"
                class="text-sm text-gray-600 mb-2 capitalize select-none cursor-pointer w-[90%] mx-auto rounded-b p-2 text-center font-bold tracking-wide bg-white"
                v-if="computedSelectedItem"
            >
                <div v-if="getting" class="my-1 text-green-600 text-sm lowercase text-center w-full relative">getting messages...</div>
                <div>{{ computedSelectedItem.name }}</div>
                <div class="w-[90%] my-1 p-2 flex justify-start items-center overflow-hidden overflow-x-auto" v-if="computedSelectedItem?.topics?.length">
                    <div
                        v-for="c in computedSubItems"
                        :key="c.id"
                        :title="c.description ? c.description : (c.about ? c.about : '')"
                        @click="() => addToSubItem(c) "
                        class="capitalize mr-3 rounded text-sm p-2 min-w-[100px] select-none transition duration-75 cursor-pointer text-center"
                        :class="[(c.id == computedSubItem?.id) ? 'hover:text-gray-700 hover:bg-gray-300 bg-gray-600 text-white' : 'text-gray-700 bg-gray-300 hover:bg-gray-600 hover:text-white']"
                    >{{ c.name }}</div>
                </div>
            </div>
            <div class="h-[350px] p-2 overflow-hidden overflow-y-auto space-y-2 flex items-center flex-col"
                :class="{'justify-end': chatMessages?.length <= 3}"
                id="message_area"
            >
                <div v-if="!getting && !computedMessagesPage && chatMessages.length" class="w-fit mx-auto my-2 text-sm text-gray-600">no more messages</div>
                <div v-if="!getting && computedMessagesPage > 1" class="w-full">
                    <div @click="getSessionMessages" class="w-fit mx-auto p-4 text-lg text-gray-600 cursor-pointer">...</div>
                </div>
                <template v-if="chatMessages.length">
                    <MessageBadge
                        v-for="(msg, idx) in chatMessages"
                        :key="idx"
                        :idx="idx"
                        :id="`message_${idx}`"
                        :msg="msg"
                        :item="selectedSession"
                        :allow-actions="computedCanSendMessage"
                        :show="!computedSubItem?.id || computedSubItem?.id == msg.topicId"
                        :current-reply="message.replying?.id && message.replying?.id == msg.id"
                        @on-success="(data) => replaceFirstMessage(data)"
                        @on-update="(data) => replaceOldMessage(data)"
                        @select-as-reply="(data) => selectAsReply(data, idx)"
                        @select-for-update="(data) => selectForUpdate(data, idx)"
                        :is-participant="isParticipant"
                    />
                </template>
                <div
                    v-else
                    class="text-gray-600 text-sm font-bold w-full h-[300px] flex justify-center items-center my-auto"
                >{{computedSelectedItem?.isSession && computedSelectedItem?.type == 'IN_PERSON' ? 'it is an in-person session' : 'no messages'}}</div>
            </div>
        </div>
        
        <div class="rounded-lg bg-stone-100 w-full p-2" v-if="computedCanSendMessage && !getting">
            <div v-if="computedSubItem?.id" class="text-center text-xs text-gray-600 my-1">
                filtered by: <span class="font-bold">{{ computedSubItem.name }}</span>
            </div>
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

        <input 
            type="file"
            name="messageFiles"
            ref="messageFilesInput"
            @change="changeFile" 
            class="hidden" id="messageFiles" multiple accept="image/*">

        <CreateTopicFormModal
            :show="modalData.show && modalData.type == 'topic'"
            :therapy="therapy"
            :loaded-sessions="sessions"
            :loaded-sessions-page="pages.session"
            @close="closeModal"
            @on-success="(data) => {
                topics = [data, ...topics]
            }"
        />

        <MediaCapture
            :show="mediaCaptureData.show"
            :type="mediaCaptureData.type"
            @close="closeMediaCapture"
            @send-file="(file) => {
                files = [file, ...files]
            }"
        />

        <Alert
            :show="alertData.show"
            :type="alertData.type"
            :message="alertData.message"
            :time="alertData.time"
            @close="clearAlertData"
        />
    </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, unref, watch, watchEffect } from 'vue';
import { default as _ } from 'lodash';
import TextBox from './TextBox.vue';
import PaperplaneIcon from '@/Icons/PaperplaneIcon.vue';
import PaperclipIcon from '@/Icons/PaperclipIcon.vue';
import TherapyFilterItem from './TherapyFilterItem.vue';
import CameraIcon from '@/Icons/CameraIcon.vue';
import FileIcon from '@/Icons/FileIcon.vue';
import MicrophoneIcon from '@/Icons/MicrophoneIcon.vue';
import useAuth from '@/Composables/useAuth';
import Checkbox from '@/Components/Checkbox.vue';
import CreateTopicFormModal from '@/Components/CreateTopicFormModal.vue';
import FormLoader from './FormLoader.vue';
import PrimaryButton from './PrimaryButton.vue';
import MessageBadge from './MessageBadge.vue';
import useModal from '@/Composables/useModal';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import FilePreview from './FilePreview.vue';
import MediaCapture from './MediaCapture.vue';

const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()
const { modalData, showModal, closeModal } = useModal()

const emits = defineEmits(['deselectActiveSession', 'updateActiveSession'])

const props = defineProps({
    therapy: {
        default: null
    },
    newTopic: {
        default: null
    },
    newSession: {
        default: null
    },
    activeSession: {
        default: null
    },
    selectedActiveSession: {
        default: false,
        type: Boolean
    },
    isParticipant: {
        default: false,
        type: Boolean
    },
    isUser: {
        default: false,
        type: Boolean
    },
    isCounsellor: {
        default: false,
        type: Boolean
    }
})

const showAttachmentIcons = ref(false)
const loading = ref(false)
const getting = ref(false)
const mediaCaptureData = ref({
    show: false,
    type: ''
})
const pages = ref({
    message: 1,
    session: 1,
    topic: 1,
})
const message = ref({
    files: [],
    deletedFiles: [],
    content: '',
    confidential: false,
    fromCounsellor: false,
    counsellorAvatar: '',
    type: 'NORMAL',
    status: 'sending',
    replying: null,
    topicId: '',
    forType: '',
    forId: '',
    toType: '',
    toId: '',
    fromType: '',
    fromId: '',
    fromUserId: '',
    toUserId: '',
    id: null,
})
const messageFilesInput = ref(null)
const replyingMessage = ref(null)
const selectedItemType = ref(null)
const selectedSessionTopic = ref(null)
const selectedSession = ref(null)
const selectedTopic = ref(null)
const selectedTopicSession = ref(null)
const files = ref([])
const deletedFiles = ref([])
const sessions = ref([])
const chatMessages = ref([])
const messages = reactive({
    sessions: {

    },
    topics: {
        
    }
})
const topics = ref([])
const filters = ref({
    sessions: false,
    topics: false
})

watch(() => filters.value.sessions, () => {
    if (filters.value.sessions && filters.value.topics)
        filters.value.topics = !filters.value.sessions

    if (pages.value.session == 1 && filters.value.sessions)
        getSessions()
})
watch(() => filters.value.topics, () => {
    if (filters.value.topics && filters.value.sessions)
        filters.value.sessions = !filters.value.topics
    
    if (pages.value.topic == 1 && filters.value.topics)
        getTopics()
})
watch(() => props.newSession?.id, () => {
    if (props.newSession?.id) sessions.value = [props.newSession, ...sessions.value]
})
watch(() => props.newTopic?.id, () => {
    if (props.newTopic?.id) topics.value = [props.newTopic, ...topics.value]
})
watchEffect(() => {
    if (
        props.activeSession?.id &&
        selectedSession.value?.id && 
        props.activeSession?.id == selectedSession.value?.id
    ) emits('deselectActiveSession')
})
watchEffect(() => {
    if (props.selectedActiveSession) {
        clickedSwitchToActiveSession()
    }
})

const computedCanSendMessage = computed(() => {
    return props.isParticipant && computedSelectSessionIsActive.value &&
        !['FAILED', 'ABANDONED', 'HELD', 'HELD_CONFIRMATION', 'PENDING', 'IN_SESSION_CONFIRMATION'].includes(props.activeSession?.status) &&
        props.activeSession?.type == 'ONLINE'
})
const computedSelectSessionIsActive = computed(() => {
    return props.activeSession?.id && selectedSession.value?.id && 
        props.activeSession?.id == selectedSession.value?.id
})
const computedFiltered = computed(() => {
    return ['session', 'topic'].includes(computedCurrentFilter.value)
})
const computedPages = computed(() => {
    return computedFiltered.value ? pages.value[computedCurrentFilter.value] : 0
})
const computedFilteredItems = computed(() => {
    return computedCurrentFilter.value == 'session'
        ? sessions.value 
        : (computedCurrentFilter.value == 'topic' ? topics.value : [])
})
const computedCurrentFilter = computed(() => {
    return filters.value.sessions
        ? 'session' 
        : ( filters.value.topics ? 'topic' : '' )
})
const computedSelectedItem = computed(() => {
    const selected = filters.value.sessions
        ? selectedSession.value 
        : ( filters.value.topics ? selectedTopic.value : null )

    return selected ?? (props.selectedActiveSession ? props.activeSession : null)
})
const computedSubItems = computed(() => {
    return (computedCurrentFilter.value == 'session' ? (computedSelectedItem.value.topics) : computedSelectedItem.value.sessions)
})
const computedSubItem = computed(() => {
    return (computedCurrentFilter.value == 'session' ? (selectedSessionTopic.value) : selectedTopicSession.value)
})
const computedMessagesPage = computed(() => {
    if (computedCurrentFilter.value == 'session' && selectedSession.value?.id)
        return messages.sessions[selectedSession.value.id]?.page
            ? messages.sessions[selectedSession.value.id]?.page
            : 0

    if (computedCurrentFilter.value == 'topic' && selectedTopic.value?.id)
        return messages.topics[selectedTopic.value.id]?.page
            ? messages.topics[selectedTopic.value.id]?.page
            : 0

    return 0
})
const computedHasMessage = computed(() => {
    return message.value.content || files.value?.length || deletedFiles.value?.length
})

function clickedSwitchToActiveSession() {
    if (!filters.value.sessions) filters.value.sessions = true

    selectedSession.value = props.activeSession
    handleSelectedSessionChange()
}

function clickedGetMore() {
    if (computedCurrentFilter.value == 'session') {
        getSessions()
        return
    }

    getTopics()
}

function closeMediaCapture() {
    mediaCaptureData.value.type = ''
    mediaCaptureData.value.show = false
}

function showMediaCapture(type) {
    mediaCaptureData.value.type = type
    mediaCaptureData.value.show = true
}

function removeUploadFile(file, idx) {
    if (file.id) {
        deletedFiles.value = [file, ...deletedFiles.value]
    }

    files.value.splice(idx, 1)
}

const debouncedSessionsGet = _.debounce(() => {
    getSessions()
}, 500)

const debouncedTopicsGet = _.debounce(() => {
    getTopics()
}, 500)

function clickedCreateTopic() {
    showModal('topic')
}

function selectAsReply(data) {
    message.value.replying = { ...data }
}

function selectForUpdate(data) {
    message.value = {...message.value, ...data}
    message.value.replying = null
    replyingMessage.value = data.replying
    message.value.deletedFiles = []
    files.value = data.files
    deletedFiles.value = []
}

function removeReply() {
    message.value.replying = null
}

function addToSubItem(item) {
    if (computedCurrentFilter.value == 'session') {
        selectedSessionTopic.value = selectedSessionTopic.value?.id == item?.id ? null : item
        selectedItemType.value = 'session'
        return
    }
        
    selectedItemType.value = 'topic'
    selectedTopicSession.value = selectedTopicSession.value?.id == item?.id ? null : item
}

function deselectItem() {
    if (computedCurrentFilter.value == 'session') {
        selectedSession.value = null
        handleSelectedSessionChange()
    }
    
    if (computedCurrentFilter.value == 'topic') {
        selectedTopic.value = null
        handleSelectedTopicChange()
    }

    selectedItemType.value = null
    scrollToBottom()
}

function handleSelectedSessionChange() {
    if (!selectedSession.value?.id) {
        chatMessages.value = []
        return
    }
    
    if (messages.sessions[selectedSession.value.id]?.data?.length) {
        chatMessages.value = [...messages.sessions[selectedSession.value.id].data]
        return
    }

    if (!messages.sessions[selectedSession.value.id]) {
        messages.sessions[selectedSession.value.id] = { data: [], page: 1 }
        getSessionMessages()
        return
    }
    
    chatMessages.value = []
}

function handleSelectedTopicChange() {
    if (!selectedTopic.value?.id) {
        chatMessages.value = []
        return
    }
    
    if (messages.topics[selectedTopic.value.id]?.data?.length) {
        chatMessages.value = [...messages.topics[selectedTopic.value.id].data]
        return
    }

    if (!messages.topics[selectedTopic.value.id]) {
        messages.topics[selectedTopic.value.id] = { data: [], page: 1 }
        getTopicMessages()
        return
    }
    
    chatMessages.value = []
}

function clickedFilterItem(item) {
    if (computedCurrentFilter.value == 'session' || props.activeSession?.id !== item?.id) {
        selectedSession.value = item
        handleSelectedSessionChange()
    }

    if (computedCurrentFilter.value == 'topic') {
        selectedTopic.value = item
        handleSelectedTopicChange()
    }
}

function changeFile(e) {
    if (e.target.files?.length) {
        files.value = [...e.target.files, ...(files.value ?? [])]
        messageFilesInput.value.value = ''
    }

    showAttachmentIcons.value = false
}

function resetMessage() {
    files.value = []
    deletedFiles.value = []
    message.value.files = []
    message.value.deletedFiles = []
    message.value.id = null
    message.value.content = ''
    message.value.confidential = false
    message.value.type = 'NORMAL'
    message.value.replying = null
    message.value.topicId = ''
    message.value.fromUserId = ''
    message.value.toUserId = ''
    message.value.forType = ''
    message.value.forId = ''
    message.value.toType = ''
    message.value.toId = ''
    message.value.fromType = ''
    message.value.fromId = ''
    message.value.status = 'sending'
    message.value.fromCounsellor = false
    message.value.counsellorAvatar = ''
}

function replaceMessage(data, idx) {
    let itemsRef = messages.sessions[selectedSession.value?.id]
    if (computedCurrentFilter.value == 'topic') {
        itemsRef = messages.topics[selectedTopicSession.value?.id]
    }

    itemsRef.data.splice(itemsRef.data.findIndex((d) => d.id == data.id), 1, {...data})
    chatMessages.value[idx] = {...data}
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

function sendMessage() {
    if (message.value?.id) {
        updateMessage()
        return
    }

    if (!selectedSession.value?.id) return

    message.value.forType = 'Session'
    message.value.forId = selectedSession.value.id

    if (replyingMessage.value?.id)
        message.value.replying = replyingMessage.value
    
    if (files.value)
        message.value.files = [...unref(files)]

    if (selectedSessionTopic.value?.id)
        message.value.topicId = selectedSessionTopic.value.id

    if (props.isCounsellor) {
        message.value.toType = 'User'
        message.value.toId = props.therapy.user.id
        message.value.toUserId = props.therapy.user.id
        message.value.fromType = 'Counsellor'
        message.value.fromId = props.therapy.counsellor.id
        message.value.fromUserId = props.therapy.counsellor.userId
        message.value.fromCounsellor = true
        message.value.counsellorAvatar = props.therapy.counsellor.avatar
    }

    if (props.isUser) {
        message.value.fromType = 'User'
        message.value.fromId = props.therapy.user.id
        message.value.fromUserId = props.therapy.user.id
        message.value.toType = 'Counsellor'
        message.value.toId = props.therapy.counsellor.id
        message.value.toUserId = props.therapy.counsellor.userId
    }

    addNewMessage(message.value)
    
    scrollToBottom()
    resetMessage()
}

function updateMessage() {
    message.value.status = 'updating'
    if (replyingMessage.value?.id)
        message.value.replying = replyingMessage.value
    else
        message.value.replying = null
    
    if (files.value?.length)
        message.value.files = [...unref(files)]
    
    if (deletedFiles.value?.length)
        message.value.deletedFiles = [...deletedFiles.value.map(f => f.id)]

    replaceOldMessage(message.value)
    
    scrollToBottom()
    resetMessage()
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
    if (getting.value || selectedSession.value?.type !== 'ONLINE') return

    getting.value = true

    await axios
        .get(route('api.session.messages.get', {
            sessionId: selectedSession.value?.id,
            page: messages.sessions[selectedSession.value?.id]?.page
        }))
        .then(async (res) => {
            console.log(res)

            if (messages.sessions[selectedSession.value?.id].page == 1)
                messages.sessions[selectedSession.value?.id].data = []

            messages.sessions[selectedSession.value?.id].data = [
                ...messages.sessions[selectedSession.value?.id].data,
                ...res.data.data,
            ]

            chatMessages.value = [...messages.sessions[selectedSession.value?.id].data.toReversed()]
            
            if (messages.sessions[selectedSession.value?.id].page == 1)
                scrollToBottom()
            else
                scrollToMessageId(`message_${res.data.data.length - 1}`)

            updateMessagesPage(res)
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
            getting.value = false
        })
}

async function getTopicMessages() {
    if (getting.value) return

    getting.value = true

    await axios
        .get(route('api.topic.messages.get', {
            topicId: selectedTopic.value?.id,
            page: messages.topics[selectedTopic.value?.id]?.page
        }))
        .then(async (res) => {
            console.log(res)
            if (messages.topics[selectedTopic.value?.id].page == 1)
                messages.topics[selectedTopic.value?.id].data = []

            messages.topics[selectedTopic.value?.id].data = [
                ...messages.topics[selectedTopic.value?.id].data,
                ...res.data.data,
            ]

            chatMessages.value = [...messages.topics[selectedTopic.value?.id].data.toReversed()]
            
            if (messages.topics[selectedTopic.value?.id].page == 1)
                scrollToBottom()
            else
                scrollToMessageId(`message_${res.data.data.length - 1}`)

            updateMessagesPage(res)
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
            getting.value = false
        })
}

async function scrollToMessageId(id) {
    if (!id) return
    
    await nextTick()

    const div = document.getElementById(`message_${id}`)
    console.log(id, div);

    if (div) div.scrollIntoView({ behavior: 'smooth', inline: 'nearest', block: 'nearest'})
}

async function scrollToBottom() {
    await nextTick()

    const div = document.getElementById(`message_area`)

    if (div) div.scrollTop = div.scrollHeight
}

async function getSessions() {
    loading.value = true

    await axios
        .get(route('api.sessions.get', {
            therapyId: props.therapy?.id,
            page: pages.value.session
        }))
        .then((res) => {
            console.log(res)
            if (pages.value.session == 1)
                sessions.value = []

            sessions.value = [...sessions.value, ...res.data.data]
            
            updatePage(res, 'session')
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}

async function getTopics() {
    loading.value = true

    await axios
        .get(route('api.topics.get', {
            therapyId: props.therapy?.id,
            page: pages.value.session
        }))
        .then((res) => {
            console.log(res)
            if (pages.value.topic == 1)
            topics.value = []
        
            topics.value = [...topics.value, ...res.data.data]
            updatePage(res, 'topic')
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}

function updatePage(res, key) {
    if (res.data.links.next) pages.value[key] = pages.value[key] + 1
    else pages.value[key] = 0
}

function updateMessagesPage(res) {
    if (res.data.links.next && computedCurrentFilter.value == 'session') {
        messages.sessions[selectedSession.value?.id].page += 1
    }
    else if (!res.data.links.next && computedCurrentFilter.value == 'session') {
        messages.sessions[selectedSession.value?.id].page = 0
    }
    else if (res.data.links.next && computedCurrentFilter.value == 'topic') {
        messages.topics[selectedTopic.value?.id].page += 1
    }
    else if (!res.data.links.next && computedCurrentFilter.value == 'topic') {
        messages.topics[selectedTopic.value?.id].page = 0
    }
}

function setPages(num) {
    pages.value.session = num
    pages.value.session = num
    pages.value.message = num
}

function clickedIcon(type) {
    if (type == 'file') {
        messageFilesInput.value.click()
    }
    else if (type == 'microphone') {
        showMediaCapture('audio')
    }
    else if (type == 'camera') {
        showMediaCapture('image')
    }

    showAttachmentIcons.value = false
}

function onUpdateItem(item) {
    let itemsRef = sessions
    let selectedItem = selectedSession

    if (item.isSession && item.id == props.activeSession?.id) emits('updateActiveSession', item)

    if (!item.isSession) {
        itemsRef = topics
        selectedItem = selectedTopic
    }

    itemsRef.value.splice(itemsRef.value.findIndex((data) => data.id == item.id), 1, item)
    
    if (selectedItem.value?.id == item.id && selectedItem.value?.isSession == item.isSession)
        selectedItem.value = item
}

function onDeleteItem(item) {
    let itemsRef = sessions
    let selectedItem = selectedSession

    if (!item.isSession) {
        itemsRef = topics
        selectedItem = selectedTopic
    }

    itemsRef.value.splice(itemsRef.value.findIndex((data) => data.id == item.id), 1)
    
    if (selectedItem.value.id == item.id && selectedItem.value?.isSession == item.isSession)
        selectedItem.value = null
}
</script>