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

                <div v-if="showOptions" class="absolute w-fit p-2 px-4 rounded bg-gray-600 text-gray-600 text-sm">
                    <div
                        v-for="(opt, idx) in options.filter((o) => {
                            return o !== view
                        })"
                        :key="idx"
                        class="bg-white my-2 p-2 w-24 cursor-pointer rounded text-center"
                        @click="() => {
                            showOptions = false
                            if (opt == 'close') {
                                return
                            }
                            if (opt == 'counsellors') {
                                if (counsellors.page == 1) getCounsellors()
                                if (counsellorLinks.page == 1) getCounsellorLinks()
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
                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]" v-if="computedIsAddedby">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Counsellor Search</div>
                    
                    <div class="p-4 bg-gray-200 my-4 text-gray-600 text-sm">
                        <div>
                            <div>Links</div>
                            <div class="my-2 text-justify w-full">A general link can be given to any counsellor. If you want a link specific to a counsellor, search for a counsellor; click/tap counsellor to reveal options and click get link.</div>
                            <div class="flex justify-start items-center space-x-3 overflow-hidden overflow-x-auto">
                                <template v-if="counsellorLinks.data?.length">
                                    <LinkComponent
                                        v-for="(link, idx) in counsellorLinks.data"
                                        :key="link.id"
                                        :link="link"
                                        @updated="(lk) => updateLink(lk, idx)"
                                        @deleted="(lk) => deleteLink(idx)"
                                        class="w-[90%] shrink-0 bg-white"
                                    />

                                    <div
                                        title="get more guardian links"
                                        @click="getCounsellorLinks"
                                        v-if="counsellorLinks.page"
                                        class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                                </template>
                                <div v-else class="h-10 flex justify-center items-center w-full">no links for counsellor discussions as at now.</div>
                            </div>
                        </div>

                        <div class="flex justify-end mt-4">
                            <PrimaryButton
                                @click="() => {
                                    selectedCounsellor = null
                                    createCounsellorLink()
                                }"
                                class="ms-4" 
                                :class="{ 'opacity-25': getting.show && getting.type == 'create link'}" 
                                :disabled="getting.show && getting.type == 'create link'"
                            >
                                get general link
                            </PrimaryButton>
                        </div>
                    </div>
                    <div v-if="getting.show && getting.type == 'counsellors'" class="text-center text-sm w-full my-4 text-green-600 bg-green-200">getting counsellors</div>
                    <div class="text-left text-sm mb-2 text-gray-600">Type name or username of counsellor in order to search. Then click counsellor to reveal options.</div>
                    <div class="w-full flex justify-center items-center my-4">
                        <TextInput
                            v-model="counsellorSearch"
                            class="w-[90%]"
                            type="text"
                            placeholder="search for counsellor"
                        />
                    </div>
                    <div class="p-2 flex justify-start space-x-3 items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="counsellors.data?.length">
                            <CounsellorComponent
                                v-for="counsellor in counsellors.data"
                                :counsellor="counsellor"
                                :has-view="false"
                                :key="counsellor.id"
                                class="w-[70%] shrink-0"
                                @click="() => selectedCounsellor = counsellor"
                            >
                                <div v-if="selectedCounsellor && selectedCounsellor.id == counsellor.id">
                                    <div v-if="counsellorStatus" class="text-green-600 text-xs text-center">{{ counsellorStatus }}</div>
                                    <div class="rounded p-1 text-sm text-gray-600 w-fit">
                                        <div
                                            @click="createCounsellorLink"
                                            class="rounded mb-2 bg-white p-1 cursor-pointer text-center transition hover:bg-gray-600 hover:text-gray-200"
                                        >get discussion link</div>
                                        <div
                                            @click="sendCounsellorRequest"
                                            class="rounded mb-2 bg-white p-1 cursor-pointer text-center transition hover:bg-gray-600 hover:text-gray-200"
                                        >send discussion request</div>
                                    </div>
                                </div>
                            </CounsellorComponent>

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
                                :visit-page="false"
                                :has-view="false"
                                class="w-[70%] shrink-0"
                                @click="() => {
                                    if (!computedIsAddedby) return
                                    deletedCounsellor = counsellor
                                }"
                            >
                                <div v-if="$page.props.auth.user?.counsellor.id !== counsellor.id">
                                    <div class="flex justify-end">
                                        <StyledLink :text="'visit page'" :href="route('counsellor.show', {counsellorId: counsellor.id})"/>
                                    </div>
                                    <div v-if="deletedCounsellor && deletedCounsellor.id == counsellor.id">
                                        <div v-if="deletedCounsellor && counsellorStatus" class="text-green-600 text-xs text-center">{{ counsellorStatus }}</div>
                                        <div class="rounded p-1 text-sm text-gray-600 w-fit">
                                            <div
                                                @click="removeCounsellor"
                                                class="rounded mb-2 bg-white p-1 cursor-pointer text-center transition hover:bg-gray-600 hover:text-gray-200"
                                            >remove counsellor</div>
                                        </div>
                                    </div>
                                </div>
                            </CounsellorComponent>

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
                    
                    <div 
                        class="rounded-lg bg-stone-100 w-full min-h-[50px] p-2 space-x-3 flex justify-start items-center overflow-hidden overflow-x-auto mb-2 transition duration-200"
                    >
                        <template v-if="sessions.data?.length">
                            <SessionBadge
                                v-for="(item, idx) in sessions.data"
                                :key="idx"
                                :session="item"
                                :is-active="item.id == selectedSession?.id"
                                class="w-[60%] shrink-0"
                                :has-actions="false"
                                @click="() => handleSelectedSessionChange(item)"
                            />
                            <div
                                v-if="sessions.page > 1"
                                title="get more"
                                @click="getSessions"
                                class="text-gray-600 text-lg cursor-pointer p-2 align-middle hover:border-gray-600 border rounded-sm border-transparent w-fit h-[30px] flex justify-center items-center">
                                <div>...</div>
                            </div>
                        </template>
                        <div v-else class="w-full shrink">
                            <div
                                class="text-gray-600 text-sm p-2 align-middle w-full text-center">
                                <div>no sesssions</div>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-lg min-h-[400px] bg-stone-200 h-full w-full shrink mb-2">
                        <div 
                            :title="`double click to deselect.`"
                            @click.self="deselectItem"
                            @dblclick="deselectItem"
                            class="text-sm text-gray-600 mb-2 capitalize select-none cursor-pointer w-[90%] mx-auto rounded-b p-2 text-center font-bold tracking-wide bg-white"
                            v-if="selectedSession"
                        >
                            <div v-if="getting.show" class="my-1 text-green-600 text-sm lowercase text-center w-full relative">getting messages...</div>
                            <div>{{ selectedSession.name }}</div>
                        </div>
                        <div class="h-[350px] p-2 overflow-hidden overflow-y-auto space-y-2 flex items-center flex-col"
                            :class="{'justify-end': chatMessages?.length <= 3}"
                            id="message_area"
                        >
                            <div v-if="!getting.show && !computedMessagesPage && chatMessages.length" class="w-fit mx-auto my-2 text-sm text-gray-600">no more messages</div>
                            <div v-if="!getting.show && computedMessagesPage > 1" class="w-full">
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
                                    :is-participant="!selectedSession"
                                />
                            </template>
                            <div
                                v-else
                                class="text-gray-600 text-sm font-bold w-full h-[300px] flex justify-center items-center my-auto"
                            >{{selectedSession?.type == 'IN_PERSON' ? 'it is an in-person session' : 'no messages'}}</div>
                        </div>
                    </div>
                </div>
                <div class="rounded-lg bg-stone-100 w-full p-2" v-if="computedCanSendMessage && !getting">
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
                            <PaperplaneIcon
                                v-if="computedHasMessage"
                                @click="() => sendMessage({forType: 'Discussion', item: discussion})"
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
import { ref, watchEffect, watch, computed, reactive } from 'vue';
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
import TextBox from './TextBox.vue';
import TextInput from './TextInput.vue';
import LinkComponent from './LinkComponent.vue';
import useAppLink from '@/Composables/useAppLink';
import PrimaryButton from './PrimaryButton.vue';
import { unref } from 'vue';
import SessionBadge from './SessionBadge.vue';
import StyledLink from './StyledLink.vue';


const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()
const { goToLogin } = useAuth()
const { createLink, getlinks } = useAppLink()
const {
    message, files, deletedFiles, computedHasMessage, replyingMessage, scrollToBottom,
    showAttachmentIcons, messageFilesInput, changeFile, resetMessage, updateMessage,
    clickedIcon, mediaCaptureData, closeMediaCapture, removeUploadFile, scrollToMessageId,
    selectForUpdate, selectAsReply, removeReply, sendMessage
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
const requestData = ref({
    'counsellorId': [],
})
const discussionCounsellors = ref({ data: [], page: 1 })
const sessions = ref({ data: [], page: 1 })
const sessionMessages = ref({ data: [], page: 1 })
const counsellors = ref({ data: [], page: 1 })
const counsellorLinks = ref({ data: [], page: 1 })
const counsellorSearch = ref('')
const sessionSearch = ref('')
const selectedSession = ref(null)
const deletedCounsellor = ref(null)
const selectedCounsellor = ref(null)
const chatMessages = ref([])
const counsellorStatus = ref('')
const messages = reactive({})
const showOptions = ref(false)
const view = ref('main')
const options = ref(['main', 'counsellors', 'messages', 'close',])

watch(() => props.show, () => {
    if (!props.show) return

    if (!discussionCounsellors.value?.data?.length) {
        discussionCounsellors.value.page = 1
        getDiscussionCounsellors()
    }
})
watchEffect(() => {
    if (props.discussion?.session?.id) {
        sessions.value.data = [props.discussion.session]
        selectedSession.value = props.discussion.session
        handleSelectedSessionChange()
        return
    }
    
    getSessions()
})
watch(() => counsellorSearch.value?.length, () => {
    if (counsellorSearch.value?.length)
        debouncedGetCounsellors()
})

const computedCanSendMessage = computed(() => {
    if (['FAILED', 'ABANDONED', 'HELD', 'HELD_CONFIRMATION', 'PENDING', 'IN_SESSION_CONFIRMATION'].includes(props.discussion?.status)) return false

    const user = usePage().props.auth.user

    if (!user?.id) return false

    if (props.discussion.addedby.userId == user.id) return true

    if (discussionCounsellors.value.data.filter((c) => c.userId == user.id).length) return true

    return false
})
const computedIsAddedby = computed(() => {
    const user = usePage().props.auth.user

    if (!user?.id || !props.discussion?.addedby) return false

    if (props.discussion.addedby.isCounsellor) return props.discussion.addedby.userId == user.id

    return props.discussion.addedby.id == user.id
})
const computedMessagesPage = computed(() => {
    if (selectedSession.value?.id)
        return messages[selectedSession.value.id]?.page
            ? messages[selectedSession.value.id]?.page
            : 0

    return 0
})

function handleSelectedSessionChange() {
    if (!selectedSession.value?.id) {
        chatMessages.value = []
        return
    }
    
    if (messages[selectedSession.value.id]?.data?.length) {
        chatMessages.value = [...messages[selectedSession.value.id].data]
        return
    }

    if (!messages[selectedSession.value.id]) {
        messages[selectedSession.value.id] = { data: [], page: 1 }
        getSessionMessages()
        return
    }
    
    chatMessages.value = []
}

const debouncedGetCounsellors = _.debounce(() => {
    counsellors.value.page = 1
    getCounsellors()
}, 500)

function clearData() {
    requestData.value.counsellorId = ''
}

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

function deselectItem() {
    selectedSession.value = null
    handleSelectedSessionChange()

    scrollToBottom()
}

async function getSessions() {
    setGetting('sessions')

    await axios.get(route(`api.sessions.get`, {
        page: sessions.value.page, 
        name: sessionSearch.value,
        therapyId: props.discussion.forId,
        groupTherapyId: props.discussion.forType == 'GroupTherapy' ? props.discussion.forId : null,
    }))
    .then((res) => {
        console.log(res)
        
        if (sessions.value.page == 1)
            sessions.value.data = []
        
        sessions.value.data = [
            ...sessions.value.data,
            ...res.data.data,
        ]

        updatePage(res, sessions)
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

function updatePage(res, data) {
    if (res.data.links.next) data.value.page = data.value.page + 1
    else data.value.page = 0
}

async function getCounsellorLinks() {
    if (!counsellorLinks.value.page) return
    setGetting('links')

    const res = await getlinks({
        page: counsellorLinks.value.page,
        type: 'DISCUSSION',
        addedbyId: usePage().props.auth.user?.counsellor?.id,
        addedbyType: 'Counsellor'
    })
    
    clearGetting()
    if (!res) return 
            
    if (counsellorLinks.value.page == 1)
        counsellorLinks.value.data = []
    
    counsellorLinks.value.data = [
        ...counsellorLinks.value.data,
        ...res.data.data,
    ]

    updatePage(res, counsellorLinks)
}

function updateLink(link, idx) {
    counsellorLinks.value.data.splice(idx, 1, link)
}

function deleteLink(idx) {
    counsellorLinks.value.data.splice(idx, 1)
}

async function sendCounsellorRequest() {
    if (!selectedCounsellor.value) {
        setSuccessAlertData({
            message: 'Please select a counsellor before proceeding.',
            time: 5000
        })
        return
    }

    counsellorStatus.value = 'sending request'

    requestData.value.counsellorId = selectedCounsellor.value.id
    await axios.post(route(`api.discussions.request`, {discussionId: props.discussion.id}), {...unref(requestData)})
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'The discussion request has been sent successfully.',
                time: 4000
            })

            if (selectedCounsellor.value) selectedCounsellor.value = null
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000
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

    counsellorStatus.value = ''
}

async function removeCounsellor() {
    if (!deletedCounsellor.value) {
        setSuccessAlertData({
            message: 'Please select a counsellor before proceeding.',
            time: 5000
        })
        return
    }

    counsellorStatus.value = 'removing counsellor'

    await axios.post(route(`api.discussions.removecounsellor`, {discussionId: props.discussion.id}), {counsellorId: deletedCounsellor.value.id})
        .then((res) => {
            console.log(res)
            
            // TODO listen to discussion events discussion.removecounsellor
            setSuccessAlertData({
                message: 'The counsellor has been removed from the discussion successfully.',
                time: 4000
            })

            discussionCounsellors.value.data.splice(discussionCounsellors.value.data.findIndex(c => c.id == deletedCounsellor.value.id), 1)
            deletedCounsellor.value = null
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000
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

    counsellorStatus.value = ''
}

async function createCounsellorLink() {
    setGetting('create link')

    const link = await createLink({
        type: 'DISCUSSION',
        addedbyId: props.discussion.addedby?.id,
        addedbyType: 'Counsellor',
        toId: selectedCounsellor.value ? selectedCounsellor.value?.id : null,
        toType: selectedCounsellor.value ? 'Counsellor' : null,
        forId: props.discussion.id,
        forType: 'Discussion',
    })

    if (selectedCounsellor.value) selectedCounsellor.value = null
    
    clearGetting()
    if (!link) return
            
    counsellorLinks.value.data = [link, ...counsellorLinks.value.data]
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
    let itemsRef = messages[selectedSession.value?.id]

    itemsRef.data.splice(itemsRef.data.findIndex((d) => d.id == data.id), 1, {...data})
    chatMessages.value.splice(chatMessages.value.findIndex((d) => d.id == data.id), 1, {...data})
}

function replaceFirstMessage(data) {
    let itemsRef = messages[selectedSession.value?.id]

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

        messages[selectedSession.value.id].data = [
            {...newMessage},
            ...messages[selectedSession.value.id].data,
        ]

        item = messages[selectedSession.value.id]
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
            page: messages[selectedSession.value?.id]?.page
        }))
        .then(async (res) => {
            console.log(res)

            if (messages[selectedSession.value?.id].page == 1)
                messages[selectedSession.value?.id].data = []

            messages[selectedSession.value?.id].data = [
                ...messages[selectedSession.value?.id].data,
                ...res.data.data,
            ]

            chatMessages.value = [...messages[selectedSession.value?.id].data.toReversed()]
            
            if (messages[selectedSession.value?.id].page == 1)
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

function updateMessagesPage(res) {
    if (res.data.links.next)
        messages[selectedSession.value?.id].page += 1
    else
        messages[selectedSession.value?.id].page = 0
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
    clearData()
    emits('close')
}
</script>