<template>
    <div
        class="relative w-full"
        v-if="showSessions"
    >
        <div id="therapy-messages-id" class="relative"></div>
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
    <div v-else-if="computedHasActions" class="flex my-2 p-2 justify-start items-center overflow-hidden overflow-x-auto space-x-2">
            <PrimaryButton
                v-if="computedCanStart"
                @click="() => clickedSessionAction('start')" class="shrink-0">start session for you</PrimaryButton>
            <PrimaryButton
                v-if="computedCanAbandon"
                @click="() => clickedSessionAction('abandon')" class="shrink-0">abondon session</PrimaryButton>
            <PrimaryButton
                v-if="computedCanEnd"
                @click="() => clickedSessionAction('end')" class="shrink-0">end session for you</PrimaryButton>
    </div>
    <div class="my-2 w-full h-1 rounded bg-stone-400"  v-if="showSessions"></div>
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
            <PrimaryButton class="shrink-0" v-if="isCounsellor" @click="clickedCreateTopic">create topic</PrimaryButton>
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
                    :has-actions="false"
                    :listen="false"
                    class="w-full sm:w-[60%] shrink-0"
                    @dblclick="() => clickedFilterItem(item)"
                    @on-update="(data) => onUpdateItem(data)"
                    @on-delete="(data) => onDeleteItem(data)"
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
                v-if="!currentTopic && computedSelectedItem"
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
            <div class="h-[350px] relative p-2 overflow-hidden overflow-y-auto space-y-2 flex items-center flex-col"
                :class="{'justify-end': chatMessages?.length <= 3}"
                id="message_area"
                ref="messageArea"
            >
                <div v-if="setting.show" class="text-sm p-2 text-green-300 bg-green-600 rounded my-2 w-full text-center sticky top-1">{{setting.type + ' current topic...'}}</div>
                <div v-if="!getting && !computedMessagesPage && chatMessages.length" class="w-fit mx-auto my-2 text-sm text-gray-600">no more messages</div>
                <div v-if="!getting && computedMessagesPage > 1" class="w-full">
                    <div @click="getSessionMessages" class="w-fit mx-auto p-4 text-lg text-gray-600 cursor-pointer">...</div>
                </div>
                <template v-if="chatMessages.length">
                    <MessageBadge
                        v-for="(msg, idx) in chatMessages.toReversed()"
                        :key="idx"
                        :idx="idx"
                        :id="`message_${idx}`"
                        :msg="msg"
                        :item="selectedSession"
                        :current-topic="currentTopic"
                        :allow-actions="computedCanSendMessage"
                        :show="!computedSubItem?.id || computedSubItem?.id == msg.topicId"
                        :current-reply="message.replying?.id && message.replying?.id == msg.id"
                        @unset-topic="clickedUnsetTopic"
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
                
                <div 
                    v-if="receivedMessages"
                    @click="() => {
                        scrollToBottom()
                        receivedMessages = 0
                    }"
                    class="cursor-pointer w-fit sticky ml-auto bottom-0 right-1 flex bg-gray-600 text-gray-300 rounded-full p-2 text-center">
                    <div class="text-base text-center text-white w-4 h-4 flex justify-center items-center">▼</div>
                    <div class="absolute -top-2 -right-1 text-xs rounded-full bg-gray-300 text-gray-600 w-4 h-4 flex justify-center items-center">{{ receivedMessages }}</div>
                </div>
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
            <div
                v-if="message.id"
                class="flex justify-between items-center"
            >
                <div
                    class="text-gray-300 bg-gray-800 rounded-full p-2 cursor-pointer w-4 flex justify-center items-center text-xs h-4"
                    @click="resetMessage"
                >x</div>
                <div class="text-xs text-gray-600 my-2 text-center">updating message</div>
            </div>
            <div class="w-[90%] mx-auto min-h-10 flex justify-center items-start space-x-2">
                <TextBox
                    rows="3"
                    class="w-full shrink"
                    v-model="message.content"
                />
                <div class="flex justify-end space-x-2 items-start">
                    <PaperplaneIcon 
                        v-if="computedHasMessage" 
                        @click="() => sendMessage({
                            item: selectedSession, topic: currentTopic,
                            addNewMessage, to: getMessageTo(), from: getMessageFrom(), action: replaceOldMessage
                        })" 
                        class="w-8 cursor-pointer p-1 h-8 rotate-45" />
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

        <MiniModal
            :show="modalData.show && ['topic action'].includes(modalData.type)"
            @close="closeModal"
        >
            <div class="select-none">
                
                <div class="text-gray-600 text-center font-bold tracking-wide">
                    Actions
                </div>

                <hr class="my-2">

                <div class="relative">
                    <div class="space-y-3 flex flex-col justify-center items-center">
                        <PrimaryButton
                            @click="() => {
                                closeModal()
                                viewSessionOrTopicMessages()
                            }" class="shrink-0">view topic messages</PrimaryButton>
                        <PrimaryButton
                            :disabled="setting.show"
                            @click="setCurrentTopic" class="shrink-0">set as current topic</PrimaryButton>
                    </div>
                </div>
            </div>
        </MiniModal>

        <CreateTopicFormModal
            :show="modalData.show && modalData.type == 'topic'"
            :therapy="therapy"
            :loaded-sessions="sessions"
            :loaded-sessions-page="pages.session"
            @close="closeModal"
            @on-success="(data) => {
                topics = [data, ...topics]
                emits('created', data)
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
import { computed, nextTick, onBeforeUnmount, reactive, ref, unref, watch, watchEffect } from 'vue';
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
import useMessage from '@/Composables/useMessage';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import FilePreview from './FilePreview.vue';
import MediaCapture from './MediaCapture.vue';
import { usePage } from '@inertiajs/vue3';
import MiniModal from './MiniModal.vue';

const { goToLogin } = useAuth()
const {
    message, files, deletedFiles, computedHasMessage, replyingMessage,
    showAttachmentIcons, messageFilesInput, messageArea,changeFile,
    resetMessage, updateMessage, clickedIcon, mediaCaptureData,
    closeMediaCapture, removeUploadFile, scrollToMessageId, scrollToBottom,
    selectForUpdate, selectAsReply, removeReply, sendMessage
} = useMessage()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()
const { modalData, showModal, closeModal } = useModal()

const emits = defineEmits([
    'deselectActiveSession', 'updateActiveSession', 'created', 'updated', 'deleted',
    'doneUpdating', 'doneDeleting', 'sessionAction', 'setTopic'
])

const props = defineProps({
    therapy: {
        default: null
    },
    newSession: {
        default: null
    },
    updatedSessionOrTopic: {
        default: null
    },
    showSessions: {
        default: true
    },
    deletedSessionOrTopic: {
        default: null
    },
    activeSession: {
        default: null
    },
    canStart: {
        default: false
    },
    canAbandon: {
        default: false
    },
    canEnd: {
        default: false
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

const userId = usePage().props.auth.user?.id
const loading = ref(false)
const getting = ref(false)
const setting = ref({show: false, type: ''})
const listening = ref(false)
const pages = ref({
    message: 1,
    session: 1,
    topic: 1,
})
const selectedItemType = ref(null)
const currentTopic = ref(null)
const removingTopic = ref(false)
const selectedSessionTopic = ref(null)
const selectedSession = ref(null)
const selectedTopic = ref(null)
const selectedTopicSession = ref(null)
const receivedMessages = ref(0)
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

watchEffect(() => {
    if (props.showSessions) return

    if (props.activeSession) {
        selectedSession.value = props.activeSession
        handleSelectedSessionChange()
        listenToMessages()
    }

    if (filters.value.topics) return

    filters.value.topics = true
    getTopics()
})
watchEffect(() => {
    if (props.activeSession?.currentTopic && currentTopic.value?.id !== props.activeSession?.currentTopic?.id) {
        currentTopic.value = props.activeSession?.currentTopic
        addCurrentTopicToChat()
        return
    }

    if (!props.activeSession?.currentTopic && currentTopic.value?.id && !props.isCounsellor)
        removeCurrentTopicFromChat()
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
watch(() => props.updatedSessionOrTopic?.id, () => {
    if (!props.updatedSessionOrTopic?.id) return
    
    if (props.updatedSessionOrTopic.isSession)
        sessions.value.splice(
            sessions.value.findIndex((s) => s.id == props.updatedSessionOrTopic.id),
            1,
            {...props.updatedSessionOrTopic}
        )
    else
        topics.value.splice(
            topics.value.findIndex((s) => s.id == props.updatedSessionOrTopic.id),
            1,
            {...props.updatedSessionOrTopic}
        )

    emits('doneUpdating')
})
watch(() => props.deletedSessionOrTopic?.id, () => {
    if (!props.deletedSessionOrTopic?.id) return
    
    if (props.deletedSessionOrTopic.isSession)
        sessions.value.splice(sessions.value.findIndex((s) => s.id == props.deletedSessionOrTopic.id), 1)
    else
        topics.value.splice(topics.value.findIndex((s) => s.id == props.deletedSessionOrTopic.id), 1)

    emits('doneDeleting')
})
watch(() => props.newSession?.id, () => {
    if (!props.newSession?.id) return
    
    sessions.value = [{...props.newSession}, ...sessions.value]
})
watchEffect(() => {
    if (
        props.activeSession?.id &&
        selectedSession.value?.id && 
        props.activeSession?.id == selectedSession.value?.id
    ) emits('deselectActiveSession')
})
watchEffect(() => {
    if (
        props.activeSession?.status == 'IN_SESSION_CONFIRMATION' && 
        usePage().props.auth.user.id !== props.activeSession?.updatedById
    ) confirmInSession()
})
watchEffect(() => {
    if (
        props.activeSession?.status == 'HELD_CONFIRMATION' && 
        usePage().props.auth.user.id !== props.activeSession?.updatedById
    ) confirmSessionHeld()
})
watchEffect(() => {
    if (
        props.activeSession?.status == 'ABANDONED' && 
        usePage().props.auth.user.id !== props.activeSession?.updatedById
    ) sessionAbandoned()
})
watchEffect(() => {
    if (props.selectedActiveSession) {
        clickedSwitchToActiveSession()
    }
})

onBeforeUnmount(() => {
    if (listening.value && props.activeSession)
        Echo.leave(`sessions.${props.activeSession.id}`)
})


const computedCanSendMessage = computed(() => {
    if (!props.isParticipant) return false

    if (
        props.activeSession?.status == 'HELD_CONFIRMATION' && 
        usePage().props.auth.user.id !== props.activeSession?.updatedById
    ) return true

    if (
        props.activeSession?.status == 'IN_SESSION_CONFIRMATION' && 
        usePage().props.auth.user.id == props.activeSession?.updatedById
    ) return true

    return computedSelectSessionIsActive.value &&
        !['FAILED', 'ABANDONED', 'HELD', 'PENDING'].includes(props.activeSession?.status) &&
        props.activeSession?.type == 'ONLINE'
})
const computedCanStart = computed(() => {
    return ['PENDING', 'IN_SESSION_CONFIRMATION'].includes(props.activeSession?.status) && 
            userId !== props.activeSession?.updatedById && 
            props.activeSession?.status !== 'IN_SESSION' &&
            props.canStart
})
const computedCanEnd = computed(() => {
    if (
        'IN_SESSION_CONFIRMATION' == props.activeSession?.status && 
        userId == props.activeSession?.updatedById
    ) return false

    return props.activeSession?.status !== 'ABANDONED' && 
        props.canEnd
})
const computedCanAbandon = computed(() => {
    return ['PENDING', 'IN_SESSION', 'IN_SESSION_CONFIRMATION']
            .includes(props.activeSession?.status) && 
            props.canAbandon
})
const computedHasActions = computed(() => {
    return computedCanStart.value || computedCanEnd.value || computedCanAbandon.value
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

function setSetting(type) {
    setting.value.type = type
    setting.value.show = true
}

function clearSetting() {
    setting.value.type = ''
    setting.value.show = false
}

function listenToMessages() {
    if (listening.value) return

    listening.value = true
    Echo
        .private(`sessions.${props.activeSession.id}`)
        .listen('.message.created', (data) => {
            console.log(data, 'message created');
            if (data.message?.fromUserId == userId)
                return

            onMessageCreated(data.message)
        })
        .listen('.session.updated', (data) => {
            emits('updated', data.session)
        })
}

function clickedSessionAction(action) {
    emits('sessionAction', action)
}

function getMessageTo() {
    let to = {
        type: '',
        id: 0,
        userId: 0,
    }

    if (props.isCounsellor) {
        to.type = 'User'
        to.id = props.therapy.user.id
        to.userId = props.therapy.user.id
    }
    
    if (props.isUser) {
        to.type = 'Counsellor'
        to.id = props.therapy.counsellor.id
        to.userId = props.therapy.counsellor.userId
    }

    return to
}

function getMessageFrom() {
    let from = {
        type: '',
        id: 0,
        userId: 0,
        isCounsellor: false,
        avatar: null,
    }

    if (props.isCounsellor) {
        from.type = 'Counsellor'
        from.id = props.therapy.counsellor.id
        from.userId = props.therapy.counsellor.userId
        from.isCounsellor = true
        from.avatar = props.therapy.counsellor.avatar
    }

    if (props.isUser) {
        from.type = 'User'
        from.id = props.therapy.user.id
        from.userId = props.therapy.user.id
    }

    return from
}

function sessionAbandoned() {
    const message = props.isCounsellor
        ? 'User has abandoned the session. You can no more continue.'
        : 'Counsellor has abandoned the session. You can no more continue.'

    setSuccessAlertData({
        message,
        time: 10000
    })
}

function confirmSessionHeld() {
    const message = props.isCounsellor
        ? 'User has ended the session on his/her end. You can no more continue. Please end the session on your end.'
        : 'Counsellor ended the session on his/her end. You can no more continue. Please end the session on your end.'

    setSuccessAlertData({
        message,
        time: 10000
    })
}

function confirmInSession() {
    const message = props.isCounsellor
        ? 'User has started the session on his/her end. Please start the session on your end.'
        : 'Counsellor ended the session on his/her end. Please start the session on your end.'

    setSuccessAlertData({
        message,
        time: 10000
    })
}

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

const debouncedSessionsGet = _.debounce(() => {
    getSessions()
}, 500)

const debouncedTopicsGet = _.debounce(() => {
    getTopics()
}, 500)

function clickedCreateTopic() {
    showModal('topic')
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
    if (!props.showSessions && props.activeSession) {
        selectedSession.value = props.activeSession
        selectedTopic.value = null
        handleSelectedSessionChange()
        return
    }

    if (computedCurrentFilter.value == 'session') {
        selectedSession.value = null
        handleSelectedSessionChange()
    }
    
    if (computedCurrentFilter.value == 'topic') {
        selectedTopic.value = null
        handleSelectedTopicChange()
    }

    selectedItemType.value = null
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

function viewSessionOrTopicMessages(item) {
    if (computedCurrentFilter.value == 'session' || props.activeSession?.id !== item?.id) {
        selectedSession.value = item
        handleSelectedSessionChange()
    }

    if (computedCurrentFilter.value == 'topic') {
        if (item && item.id !== selectedTopic.value?.id)
            selectedTopic.value = item
        handleSelectedTopicChange()
    }
}

async function setCurrentTopicOnBackend() {
    setSetting('setting')

    await axios
        .post(route('api.session.topic.set', {
            sessionId: props.activeSession?.id,
        }), {
            topicId: currentTopic.value.id
        })
        .then(async (res) => {
            console.log(res)
            setSuccessAlertData({
                message: `'${currentTopic.value.name}' has successfully been set as current topic.`,
                time: 4000
            })

            addCurrentTopicToChat()
        })
        .catch((err) => {
            console.log(err)

            currentTopic.value = null
            selectedSession.value = null
            if (err?.response?.message) {
                setFailedAlertData({
                    message: err?.response?.message,
                    time: 5000
                })
                return
            }

            setFailedAlertData({
                message: 'Failed to set topic is current topic. Please try again shortly.',
                time: 5000
            })

            goToLogin(err)
        })

    clearSetting()
}

async function unsetCurrentTopicOnBackend() {
    if (!currentTopic.value) return
    setSetting('unsetting')

    await axios
        .post(route('api.session.topic.unset', {
            sessionId: props.activeSession?.id,
        }), {
            topicId: currentTopic.value.id
        })
        .then(async (res) => {
            console.log(res)
            setSuccessAlertData({
                message: `'${currentTopic.value.name}' has successfully been removed as current topic.`,
                time: 4000
            })

            removeCurrentTopicFromChat()
        })
        .catch((err) => {
            console.log(err)

            if (err?.response?.message) {
                setFailedAlertData({
                    message: err?.response?.message,
                    time: 5000
                })
                return
            }

            setFailedAlertData({
                message: 'Failed to remove topic is current. Please try again shortly.',
                time: 5000
            })

            goToLogin(err)
        })

    clearSetting()
}

function clickedUnsetTopic() {
    if (!props.isCounsellor) return

    unsetCurrentTopicOnBackend()
}

function removeCurrentTopicFromChat() {
    if (!currentTopic.value || removingTopic.value) return
    currentTopic.value = null
    removingTopic.value = true
    const data = {name: 'end of topic', scroll: true, end: true}

    if (messages.sessions[props.activeSession.id]?.data)
        messages.sessions[props.activeSession.id].data = [
            data,
            ...messages.sessions[props.activeSession.id].data,
        ]

    chatMessages.value = [data, ...chatMessages.value]
}

function addCurrentTopicToChat() {
    if (!currentTopic.value || !props.activeSession) return

    if (removingTopic.value) {
        removingTopic.value = false
        return
    }

    if (
        !messages.sessions[props.activeSession.id]?.page ||
        messages.sessions[props.activeSession.id]?.page == 1
    ) return

    console.log(3);
    const data = {...currentTopic.value, scroll: true}

    if (messages.sessions[props.activeSession.id]?.data)
        messages.sessions[props.activeSession.id].data = [
            data,
            ...messages.sessions[props.activeSession.id].data,
        ]

    chatMessages.value = [data, ...chatMessages.value]
}

async function setCurrentTopic() {
    currentTopic.value = selectedTopic.value

    await nextTick()

    setCurrentTopicOnBackend()

    closeModal()
}

function clickedFilterItem(item) {
    if (!props.showSessions && props.isCounsellor && !item.isSession) {
        selectedTopic.value = item
        showModal('topic action')
        return
    }

    viewSessionOrTopicMessages(item)
}

function replaceOldMessage(data) {
    let itemsRef = messages.sessions[selectedSession.value?.id]
    if (computedCurrentFilter.value == 'topic' && selectedTopicSession.value) {
        itemsRef = messages.topics[selectedTopicSession.value?.id]
    }

    itemsRef.data.splice(itemsRef.data.findIndex((d) => d.id == data.id), 1, {...data})
    chatMessages.value.splice(chatMessages.value.findIndex((d) => d.id == data.id), 1, {...data})
}

function replaceFirstMessage(data) {
    let itemsRef = messages.sessions[selectedSession.value?.id]
    if (computedCurrentFilter.value == 'topic' && selectedTopicSession.value) {
        itemsRef = messages.topics[selectedTopicSession.value?.id]
    }

    if (itemsRef.data.length)
        itemsRef.data[0] = {...data}

    chatMessages.value[chatMessages.value.length - 1] = {...data}
}

function onMessageCreated(data) {
    // data.scroll = true
    addNewMessage(data)
    alertOfNewMessage()
}

function alertOfNewMessage() {
    receivedMessages.value += 1

    setTimeout(() => {
        receivedMessages.value = 0
    }, 10000)
}

function addNewMessage(newMessage) {
    let item
    if (!newMessage || messageExists(newMessage)) return

    if (computedCurrentFilter.value == 'session' || !props.showSessions) {

        messages.sessions[selectedSession.value.id].data = [
            {...newMessage},
            ...messages.sessions[selectedSession.value.id].data,
        ]

        item = messages.sessions[selectedSession.value.id]
    }

    if (computedCurrentFilter.value == 'topic' && selectedTopic.value) {

        messages.topics[selectedTopic.value.id].data = [
            {...newMessage},
            ...messages.topics[selectedTopic.value.id].data,
        ]

        item = messages.topics[selectedTopic.value.id]
    }

    if (item)
        chatMessages.value.unshift({...newMessage})
}

function messageExists(newMessage) {
    if (!newMessage?.id) return false

    if (
        (computedCurrentFilter.value == 'session' || !props.showSessions) &&
        selectedSession.value &&
        messages.sessions[selectedSession.value.id] &&
        messages.sessions[selectedSession.value.id].data?.findIndex((message) => message.id == newMessage.id) !== -1
    ) return true

    if (
        computedCurrentFilter.value == 'topic' && 
        selectedTopic.value &&
        messages.topics[selectedTopic.value.id] &&
        messages.topics[selectedTopic.value.id].data?.findIndex((message) => message.id == newMessage.id) !== -1
    ) return true

    return false
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

            chatMessages.value = [...messages.sessions[selectedSession.value?.id].data]

            if (messages.sessions[selectedSession.value?.id].page == 1 && chatMessages.value.length)
                chatMessages.value[0].scroll = true
            else if (chatMessages.value.length > 10)
                chatMessages.value[11].scroll = true

            updateMessagesPage(res)
            await nextTick()
            
            addCurrentTopicToChat()
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

            chatMessages.value = [...messages.topics[selectedTopic.value?.id].data]
            
            if (messages.topics[selectedTopic.value?.id].page == 1 && chatMessages.value?.length)
                chatMessages.value[0].scroll = true
            else if (chatMessages.value.length > 10)
                chatMessages.value[11].scroll = true

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
    if (
        (!props.showSessions && selectedSession.value && messages.sessions[selectedSession.value?.id].page == 1) ||
        (res.data.links.next && computedCurrentFilter.value == 'session' && selectedSession.value)
    ) {
        messages.sessions[selectedSession.value?.id].page += 1
    }
    else if (!res.data.links.next && computedCurrentFilter.value == 'session' && selectedSession.value) {
        messages.sessions[selectedSession.value?.id].page = 0
    }
    else if (res.data.links.next && computedCurrentFilter.value == 'topic' && selectedTopic.value) {
        messages.topics[selectedTopic.value?.id].page += 1
    }
    else if (!res.data.links.next && computedCurrentFilter.value == 'topic' && selectedTopic.value) {
        messages.topics[selectedTopic.value?.id].page = 0
    }
}

function setPages(num) {
    pages.value.session = num
    pages.value.session = num
    pages.value.message = num
}

function onUpdateItem(item) {
    let itemsRef = sessions
    let selectedItem = selectedSession

    emits('updated', item)

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

    emits('deleted', item)

    if (!item.isSession) {
        itemsRef = topics
        selectedItem = selectedTopic
    }

    itemsRef.value.splice(itemsRef.value.findIndex((data) => data.id == item.id), 1)
    
    if (selectedItem.value?.id == item.id && selectedItem.value?.isSession == item.isSession)
        selectedItem.value = null
}
</script>