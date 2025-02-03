<script setup>
import { computed, onBeforeMount, ref, unref, watch, watchEffect } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import useLocation from "@/Composables/useLocation";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Select from "./Select.vue";
import { addMinutes, format, differenceInMinutes } from 'date-fns';
import useErrorHandler from '@/Composables/useErrorHandler';
import SessionBadge from './SessionBadge.vue';
import useAuth from '@/Composables/useAuth';
import { usePage } from '@inertiajs/vue3';

const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()
const { getCurrentLocation, currentLocation } = useLocation()
const { setErrorData, clearErrorData } = useErrorHandler()
const { goToLogin } = useAuth()

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    therapy: {
        default: null,
    },
    forType: {
        default: null,
    },
    loadedSessions: {
        default: [],
    }
})

const emits = defineEmits(['closeModal', 'onSuccess'])

const loading = ref(false)
const sessionSearch = ref('')
const sessions = ref({data: [], page: 1})
const getting = ref({show: '', type: ''})
const startTime = ref('')
const endTime = ref('')
const discussionData = ref({
    'name': '',
    'description': '',
    'startTime': '',
    'endTime': '',
    'sessionId': '',
})
const discussionErrors = ref({
    'name': '',
    'description': '',
    'startTime': '',
    'endTime': '',
    'sessionId': '',
})

onBeforeMount(() => {
    startTime.value = getDefaultTime(30)
    endTime.value = getDefaultTime(70)
})

watch(() => sessionSearch.value?.length, () => {
    if (sessionSearch.value?.length)
        debouncedGetSessions()
})
watchEffect(() => {
    if (props.loadedSessions?.length)
        sessions.value.data = [...props.loadedSessions]
})
watch(() => startTime.value, () => {
    if (
        !isMinutesBefore({
            firstTime: endTime.value, 
            secondTime: startTime.value, 
            minutes: 30
        })
    ) endTime.value = addMinitesToDate(startTime.value, 30)
})
watch(() => endTime.value, () => {
    if (
        !isMinutesBefore({
            firstTime: endTime.value, 
            secondTime: startTime.value, 
            minutes: 30
        })
    ) endTime.value = addMinitesToDate(startTime.value, 30)
})

const computedStartTime = computed(() => {
    return new Date(startTime.value).toGMTString()
})
const computedEndTime = computed(() => {
    return new Date(endTime.value).toGMTString()
})
const computedNow = computed(() => {
    return new Date().toISOString().slice(0, 16)
})
const computedDuration = computed(() => {
    return (endTime.value && startTime.value) ? differenceInMinutes(new Date(endTime.value), new Date(startTime.value)) : ''
})

const debouncedGetSessions = _.debounce(() => {
    sessions.value.page = 1
    discussionData.value.sessionId = ''
    getSessions()
}, 500)

function isMinutesBefore({firstTime, secondTime = null, minutes}) {
    if (!firstTime) return null

    const comparisonDate = addMinutes(secondTime ? new Date(secondTime) : new Date(), minutes)

    return new Date(firstTime) >= comparisonDate
}

function getDefaultTime(minutes) {
    const now = new Date()
    let time = addMinutes(now, minutes).toISOString().slice(0, 16)
    return time
}

function formatDateTimeForFrontend(dateTime) {
    return format(dateTime, "yyyy-MM-dd'T'HH:mm")
}

function addMinitesToDate(time, minutes) {
    if (!time) return null
    const newTime = formatDateTimeForFrontend(addMinutes(time, minutes))
    
    return newTime
}

async function createDiscussion() {
    if (!discussionData.value.name) {
        setFailedAlertData({
            message: "Name is required for a session.",
        });
        return
    }

    if (!isMinutesBefore({ firstTime: startTime.value, minutes: 25 })) {
        setFailedAlertData({
            message: 'Start time must be at least 25 minutes away from current time. Please increase the start time.',
        })
        return
    }
    
    if (!isMinutesBefore({ firstTime: endTime.value, secondTime: startTime.value, minutes: 30})) {
        setFailedAlertData({
            message: 'End time must be at least 30 minutes away from start time. Please increase the end time.',
        })
        return
    }

    discussionData.value.startTime = new Date(startTime.value).toISOString()
    discussionData.value.endTime = new Date(endTime.value).toISOString()

    loading.value = true
    clearErrorData(discussionErrors, ['name', 'description', 'startTime', 'endTime', 'sessionId'])
    await axios.post(route(`api.discussions.create`), {
        forId: props.therapy.id,
        forType: props.forType,
        addedbyId: usePage().props.auth.user?.counsellor?.id,
        addedbyType: 'Counsellor',
        ...discussionData.value
    })
    .then((res) => {
        console.log(res)
        
        setSuccessAlertData({
            message: 'The discussion has been successfully created.',
        })

        emits('onSuccess', res.data.discussion)
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
        therapyId: props.therapy.id,
        groupTherapyId: props.forType == 'GroupTherapy' ? props.therapy.id : null,
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
    startTime.value = ''
    endTime.value = ''
    discussionData.value.endTime = ''
    discussionData.value.sessionId = ''
}

function closeModal() {
    clearData()
    emits('closeModal')
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
                >Create Discussion</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`creating discussion`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="createDiscussion"
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
                            <div class="text-sm text-center mb-2 font-bold">Session</div>
                            <div class="text-sm text-start mb-2 text-gray-600">Search and click sessions for this {{ forType }} if you want to limit discussion to a specific session.</div>
                            
                            <div class="mt-4 mx-auto max-w-[400px]">

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
                                            :therapy="therapy"
                                            :has-actions="false"
                                            @click="() => discussionData.sessionId = session.id"
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