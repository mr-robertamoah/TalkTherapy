<script setup>
import { ref, watch, watchEffect } from 'vue';
import useScheduleTimes from '@/Composables/useScheduleTimes'
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import useErrorHandler from '@/Composables/useErrorHandler';
import SessionBadge from './SessionBadge.vue';
import useAuth from '@/Composables/useAuth';
import { usePage } from '@inertiajs/vue3';

const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()
const { setErrorData, clearErrorData } = useErrorHandler()
const { goToLogin } = useAuth()

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    discussion: {
        default: null,
    },
})

const emits = defineEmits(['close', 'onUpdate'])

const deletedSession = ref(null)
const loading = ref(false)
const sessionSearch = ref('')
const sessions = ref({data: [], page: 1})
const getting = ref({show: '', type: ''})
// SCRUM-379: shared start/end handling (resources/js/Composables/useScheduleTimes.js).
const { startTime, endTime, setTimes, clearTimes, timesError, computedStartTime, computedEndTime, computedNow, computedDuration } = useScheduleTimes({ isOpen: () => props.show })
const discussionData = ref({
    'name': '',
    'description': '',
    'startTime': '',
    'endTime': '',
    'sessionId': '',
    'deletedSessionId': '',
})
const discussionErrors = ref({
    'name': '',
    'description': '',
    'startTime': '',
    'deletedSessionId': '',
    'endTime': '',
    'sessionId': '',
})

watch(() => props.show, () => {
    if (!props.show) return

    setDiscussionFormData()
})
watch(() => sessionSearch.value?.length, () => {
    if (sessionSearch.value?.length)
        debouncedGetSessions()
})
watchEffect(() => {
    if (props.loadedSessions?.length)
        sessions.value.data = [...props.loadedSessions]
})


const debouncedGetSessions = _.debounce(() => {
    sessions.value.page = 1
    discussionData.value.sessionId = ''
    getSessions()
}, 500)

function setDiscussionFormData() {
    discussionData.value.name = props.discussion.name
    discussionData.value.description = props.discussion.description
    discussionData.value.startTime = props.discussion.startTime
    setTimes(props.discussion.startTime, props.discussion.endTime)
    discussionData.value.endTime = props.discussion.endTime
    discussionData.value.sessionId = ''
    discussionData.value.deletedSessionId = null
}

async function updateDiscussion() {
    if (!discussionData.value.name) {
        setFailedAlertData({
            message: "Name is required for a session.",
        });
        return
    }

    const timeError = timesError()
    if (timeError) {
        setFailedAlertData({ message: timeError })
        return
    }

    discussionData.value.startTime = new Date(startTime.value).toISOString()
    discussionData.value.endTime = new Date(endTime.value).toISOString()
    discussionData.value.deletedSessionId = deletedSession.value?.id

    loading.value = true
    clearErrorData(discussionErrors, ['name', 'description', 'startTime', 'endTime', 'sessionId'])
    await axios.post(route(`api.discussions.update`, {discussionId: props.discussion.id}), {
        ...discussionData.value
    })
    .then((res) => {
        
        setSuccessAlertData({
            message: 'The discussion has been successfully updated.',
        })

        emits('onUpdate', res.data.discussion)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.status == 422) {
            setErrorData(discussionErrors, err.response.data.errors, ['name', 'description', 'startTime', 'sessionId', 'endTime'])
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

    loading.value = false
}

function updatePage(res, data) {
    if (res.data.links.next) data.value.page = data.value.page + 1
    else data.value.page = 0
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
            })
            return
        }

        if (err.alert) {
            setFailedAlertData({
                message: err.alert,
            })
            return
        }

        setFailedAlertData({
            message: 'Something unfortunate happened. Please try again later.',
        })
    
    })

    clearGetting()
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
}

function clearData() {
    discussionData.value.name = ''
    discussionData.value.description = ''
    discussionData.value.startTime = ''
    clearTimes()
    discussionData.value.endTime = ''
    discussionData.value.sessionId = ''
    discussionData.value.deletedSessionId = ''
}

function closeModal() {
    clearData()
    emits('close')
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="select-none relative">

            <div class="p-4 w-full mt-2 mb-4">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Discussion</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`creating discussion`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="updateDiscussion"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="name" value="Name" />

                                <TextInput
                                    id="name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="discussionData.name"
                                    required
                                    autofocus
                                />
                                
                                <InputError class="mt-2" :message="discussionErrors.name" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="description" value="Description" />

                                <TextBox
                                    id="description"
                                    class="mt-1 block w-full"
                                    v-model="discussionData.description"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives the counsellor an idea about what is to be discussed.</div>
                                <InputError class="mt-2" :message="discussionErrors.description" />
                            </div>
                        </div>

                        <div class="p-4 rounded bg-gray-200 shadow-sm my-4">
                            
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <div class="text-sm text-center mb-2 font-bold">Current Session</div>
                                <div v-if="discussion.session" class="text-sm text-start mb-2 text-gray-600">Click on the session to remove or restore.</div>

                                <div
                                    v-if="discussion.session"
                                    :class="[deletedSession ? 'bg-red-300' : 'bg-green-300']"
                                    class="flex space-x-3 justify-start items-center overflow-hidden overflow-x-auto p-2">
                                    <SessionBadge
                                        :session="discussion.session"
                                        :is-active="false"
                                        :has-actions="false"
                                        :show-details="false"
                                        @click="() => {
                                            if (deletedSession) {
                                                deletedSession = null
                                                discussionData.sessionId = discussion.session.id
                                                return
                                            }
                                            deletedSession = discussion.session
                                            discussionData.sessionId = ''
                                        }"
                                        class="shrink-0 w-[60%]"
                                    />
                                </div>
                                <div v-else class="h-10 flex justify-center items-center w-full">no discussion session for now.</div>
                            </div>  
                                
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <div class="text-sm text-center mb-2 font-bold">Session</div>
                                <div class="text-sm text-start mb-2 text-gray-600">Search and click sessions for this {{ discussion.forType }} if you want to limit discussion to a specific session.</div>
                                
                                <TextInput
                                    placeholder="search for sessions"
                                    type="text"
                                    class="mt-1 mb-2 block w-full"
                                    v-model="sessionSearch"
                                />

                                <div class="flex space-x-3 justify-start items-center overflow-hidden overflow-x-auto p-2">
                                    <template v-if="sessions.data?.length">
                                        <SessionBadge
                                            v-for="session in sessions.data"
                                            :session="session"
                                            :is-active="session.id == discussionData.sessionId"
                                            :key="session.id"
                                            :has-actions="false"
                                            @click="() => {
                                                if (!deletedSession) {
                                                    deletedSession = discussion.session
                                                }
                                                discussionData.sessionId = session.id
                                            }"
                                            class="shrink-0 w-[60%]"
                                        />

                                        <div
                                            title="get more guardian links"
                                            @click="getSessions"
                                            v-if="sessions.page && sessionSearch"
                                            class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                                    </template>
                                    <div v-else class="h-10 flex justify-center items-center w-full">no sessions for now.</div>
                                </div>
                                
                                <InputError class="mt-2" :message="discussionErrors.sessionId" />
                            </div>
                        </div>

                        <div class="p-4 rounded bg-gray-200 shadow-sm my-4">
                            <div class="text-sm text-center mb-2 font-bold">Start and End Times</div>
                            
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="startTime" value="Start Time" />

                                <TextInput
                                    id="startTime"
                                    type="datetime-local"
                                    class="mt-1 block w-full"
                                    v-model="startTime"
                                    :min="computedNow"
                                    required
                                />
                                
                                <div class="text-xs p-1 text-end text-gray-600">{{ computedStartTime }}</div>
                                <InputError class="mt-2" :message="discussionErrors.startTime" />
                            </div>
                            
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="endTime" value="End Time" />

                                <TextInput
                                    id="endTime"
                                    type="datetime-local"
                                    class="mt-1 block w-full"
                                    v-model="endTime"
                                    :min="computedNow"
                                    required
                                />
                                
                                <div class="text-xs p-1 text-end text-gray-600">{{ computedEndTime }}</div>
                                <InputError class="mt-2" :message="discussionErrors.endTime" />
                            </div>
                            
                            <div class="mt-4 mx-auto max-w-[400px]" v-if="computedDuration">
                                <div class="text-xs p-1 text-end text-gray-600">Duration: {{ computedDuration }} minutes</div>
                            </div>
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
</template>