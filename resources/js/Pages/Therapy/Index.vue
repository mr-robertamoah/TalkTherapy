<script setup>
import BooleanAttribute from '@/Components/BooleanAttribute.vue';
import CounsellorComponent from '@/Components/CounsellorComponent.vue';
import ActivityBadge from '@/Components/ActivityBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import UserComponent from '@/Components/UserComponent.vue';
import CreateReportModal from '@/Components/CreateReportModal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import DangerButton from '@/Components/DangerButton.vue';
import NameAndValue from '@/Components/NameAndValue.vue';
import TherapyComponent from '@/Components/TherapyComponent.vue';
import MiniModal from '@/Components/MiniModal.vue';
import useModal from '@/Composables/useModal';
import TextInput from '@/Components/TextInput.vue';
import FormLoader from '@/Components/FormLoader.vue';
import { default as _ } from 'lodash';
import { differenceInMinutes } from 'date-fns';
import useAlert from '@/Composables/useAlert';
import Alert from '@/Components/Alert.vue';
import UpdateIndividualTherapyFormModal from '@/Components/UpdateIndividualTherapyFormModal.vue';
import CreateSessionFormModal from '@/Components/CreateSessionFormModal.vue';
import useAuth from '@/Composables/useAuth';
import useLocalDateTimed from '@/Composables/useLocalDateTime';

const { modalData, showModal, closeModal } = useModal()
const { goToLogin } = useAuth()
const { toDiffForHumans } = useLocalDateTimed()
const { alertData, clearAlertData, setAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const props = defineProps({
    therapy: {
        default: null
    },
    pendingRequest: {
        default: null
    }
})
const scrollItems = [
    { id: 'therapy_background_story', name: 'background story' },
    { id: 'therapy_participants', name: 'participants' },
    { id: 'therapy_details', name: 'details' },
    { id: 'therapy_payment_details', name: 'payment details' },
    { id: 'therapy_other_details', name: 'other details' },
    { id: 'therapy_stats', name: 'stats' },
]

const userId = usePage().props.auth.user?.id
const loader = ref({
    show: false,
    type: ''
})
const timer = ref({
    beforeStart: 0,
    beforeEnd: 0,
})
const counsellorSearch = ref('')
const sessionActionRunning = ref('')
const request = ref({ responding: false, status: null })
const showAll = ref(false)
const interval = ref(null)
const mainDiv = ref(null)
const newSession = ref(null)
const activeSession = ref(null)
const counsellor = ref(null)
const selectedActiveSession = ref(false)
const activeItemId = ref(scrollItems[0].id)
const onlineParticipants = ref([])
const searchedCounsellors = ref([])
const selectedCounsellors = ref([])
const page = ref(1)
const therapyForm = useForm({})

watch(() => counsellorSearch.value, () => {
    if (counsellorSearch.value)
        debouncedGetCounsellors()
})
watchEffect(() => {
    if (props.therapy?.id) {
        waitForAlert()
        counsellor.value = props.therapy.data 
            ? props.therapy.data.counsellor
            : props.therapy.counsellor
    }
})
watch(() => props.therapy?.activeSession?.id, () => {
    if (props.therapy?.activeSession?.id) {
        activeSession.value = props.therapy.activeSession
        startTimer()
    }
})
watchEffect(() => {
    let currentTherapy = props.therapy?.data ? props.therapy?.data : props.therapy

    if (!userId || !currentTherapy.id) return

    Echo
        .join(`therapies.${currentTherapy.id}`)
        .here((users) => {
            onlineParticipants.value = [...users]

            let otherUser = users.find((u) => userId !== u.id)
            if (!otherUser) return

            setSuccessAlertData({
                message: `${otherUser.name} is already online.`,
                time: 4000
            })
        })
        .joining((user) => {
            let found = onlineParticipants.value.find((u) => u.id == user.id)

            if (found) return

            onlineParticipants.value.push(user)

            setSuccessAlertData({
                message: `${user.name} just came online.`,
                time: 4000
            })
        })
        .leaving((user) => {
            onlineParticipants.value.splice(
                onlineParticipants.value.findIndex((u) => u.id == user.id),
                1,
                user
            )

            setSuccessAlertData({
                message: `${user.name} just went offline.`,
                time: 4000
            })
        })
        .listen(`.session.started`, (data) => {
            activeSession.value = data.session
        })
        .listen(`.session.updated`, (data) => {
            if (activeSession.value?.id == data.session.id)
                activeSession.value = data.session
        })
})

onBeforeUnmount(() => {
    if (interval.value) clearInterval(interval.value)
    let currentTherapy = props.therapy?.data ? props.therapy?.data : props.therapy

    if (!userId || !currentTherapy.id) return

    Echo.leave(`therapies.${currentTherapy.id}`)
})

const computedIsUser = computed(() => {
    return userId == computedTherapy.value.user?.id
})
const computedIsCounsellor = computed(() => {
    return userId == counsellor.value?.userId
})
const computedIsInSession = computed(() => {
    let session = activeSession?.value ?? computedTherapy.value?.activeSession

    return ['IN_SESSION', 'IN_SESSION_CONFIRMATION', 'HELD_CONFIRMATION'].includes(session?.status) ||
        session?.status == 'PENDING' && timer.value.beforeStart < 5
})
const computedIsParticipant = computed(() => {
    return computedIsUser.value || computedIsCounsellor.value
})
const computedTherapy = computed(() => {
    return props.therapy?.data ? props.therapy.data : props.therapy
})
const computedCanParticipate = computed(() => {
    const user = usePage().props.auth.user

    if (user?.id == computedTherapy.value.user?.id || user?.counsellor) return true

    return false
})

function clickedAssistanceRequest() {
    showModal('request assistance')
}

function setTimers() {
    let now = new Date()
    let startTime = new Date(activeSession.value.startTime)
    let endTime = new Date(activeSession.value.endTime)

    timer.value.beforeStart = differenceInMinutes(startTime, now)
    timer.value.beforeEnd = differenceInMinutes(endTime, now)
}

function clearData() {
    searchedCounsellors.value = []
    selectedCounsellors.value = []
    counsellorSearch.value = ''
    page.value = 1
}
 
async function deleteTherapy() {
    therapyForm.delete(route(`therapies.delete`, { therapyId: computedTherapy.value.id }), {
        onStart: () => {
            setLoader('delete')
        },
        onFinish: () => {
            endLoader()
        },
        onError: (err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setAlertData({
                    message: err.response.data.message,
                    type: 'failed',
                    show: true,
                })
                return
            }

            setAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                type: 'failed',
                show: true,
            })
        },
        onSuccess: (res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your therapy has been successfully deleted.',
                type: 'success',
                show: true,
                time: 4000
            })
            closeModal()
        }
    })
}
 
async function endTherapy() {
    therapyForm.post(route(`therapies.end`, { therapyId: computedTherapy.value.id }), {
        onStart: () => {
            setLoader('delete')
        },
        onFinish: () => {
            endLoader()
        },
        onError: (err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setAlertData({
                    message: err.response.data.message,
                    type: 'failed',
                    show: true,
                })
                return
            }

            setAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                type: 'failed',
                show: true,
            })
        },
        onSuccess: (res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your therapy has been successfully deleted.',
                type: 'success',
                show: true,
                time: 4000
            })
            closeModal()
        }
    })
}

function setLoader(type) {
    loader.value.type = type
    loader.value.show = true
}

function endLoader() {
    loader.value.type = ''
    loader.value.show = false
}

function resetTimer() {
    timer.value.beforeEnd = 0
    timer.value.beforeStart = 0
}

function startTimer() {
    resetTimer()

    setTimers()
    interval.value = setInterval(() => {
        timer.value.beforeStart -= 1
        timer.value.beforeEnd -= 1
    }, 60000)
}

function addToSelected(counsellor) {
    selectedCounsellors.value = [...selectedCounsellors.value.filter((c) => c.id !== counsellor.id), counsellor]
}

function removeFromSelected(counsellor) {
    selectedCounsellors.value = [...selectedCounsellors.value.filter((c) => c.id !== counsellor.id)]
}

const debouncedGetCounsellors = _.debounce(() => {
    getCounsellors()
}, 500)

function updatePage(res) {
    if (res.data.links.next) page.value += 1
    else page.value = 0
}

function clickedDelete() {
    showModal('delete')
}

function clickedUpdate() {
    showModal('update')
}

function clickedEndTherapy() {
    showModal('end')
}

function clickedCreateSession() {
    showModal('create session')
}

function clickedReport() {
    showModal('report')
}

function clickedActiveSession() {
    if (activeSession.value?.status == 'ABANDONED') return

    selectedActiveSession.value = true
}

async function sendAssistanceRequest() {
    setLoader('assistance')
    await axios
        .post(route(`therapies.assist`, { therapyId: computedTherapy.value?.id}),{
            counsellorIds: selectedCounsellors.value.map((c) => c.id)
        })
        .then((res) => {
            console.log(res)
            setSuccessAlertData({
                message: computedIsUser.value 
                    ? "You have successfully requested for assistance for this therapy." 
                    : "You have successfully sent an assistance request for this therapy.",
                time: 4000,
            })

            closeModal()
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            setFailedAlertData({
                message: computedIsUser.value ? "Your request for assistance failed, please try again in a short while." : "Your request to assist has failed, please try again in a short while.",
                time: 4000,
            })
        })

    endLoader()
}

function isNotParticipant() {
    return userId !== props.therapy?.counsellor?.userId && userId !== props.therapy?.user?.id
}

async function waitForAlert() {
    if (isNotParticipant()) return

    await axios
        .post(route(`alert.wait`),{
            alertableType: 'Therapy',
            alertableId: props.therapy?.id
        })
        .then((res) => {
            console.log(res)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
}

async function getCounsellors() {
    setLoader('counsellors')
    await axios.get(`/requests/counsellors?name=${counsellorSearch.value}&page=${page.value}`)
        .then((res) => {
            console.log(res)
            if (page.value > 1) {
                searchedCounsellors.value = [...searchedCounsellors.value, ...res.data.data]
                updatePage(res)
                return
            }

            searchedCounsellors.value = [...res.data.data]
            updatePage(res)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })

    endLoader()
}

function scrollToItem(item) {
    const itemDiv = document.getElementById(item.id)
    
    if (itemDiv) {
        itemDiv.scrollIntoView({ inline: 'center', behavior: 'smooth' })
        activeItemId.value = item.id
    }
}

async function clickedAbandonSession() {
    if (!activeSession.value?.id) return

    sessionActionRunning.value = 'abandoning session'
    await axios.post(route('api.sessions.abandon', activeSession.value.id))
        .then((res) => {
            activeSession.value = res.data.session
        })
        .catch((err) => {
            console.log(err);
            goToLogin(err)
            setFailedAlertData({
                message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
                timer: 4000,
            })
        })
        .finally(() => {
            sessionActionRunning.value = ''
        })
}

async function clickedStartSession() {
    if (!activeSession.value?.id) return

    sessionActionRunning.value = 'starting session'
    await axios.post(route('api.sessions.in_session', activeSession.value.id))
        .then((res) => {
            activeSession.value = res.data.session
        })
        .catch((err) => {
            console.log(err);
            goToLogin(err)
            setFailedAlertData({
                message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
                timer: 4000,
            })
        })
        .finally(() => {
            sessionActionRunning.value = ''
        })
}

function reportCreated(report) {
    console.log(report)
}

async function clickedEndSession() {
    if (!activeSession.value?.id) return

    sessionActionRunning.value = 'ending session'
    await axios.post(route('api.sessions.end', activeSession.value.id))
        .then((res) => {
            activeSession.value = res.data.session
        })
        .catch((err) => {
            console.log(err);
            goToLogin(err)
            setFailedAlertData({
                message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
                timer: 4000,
            })
        })
        .finally(() => {
            sessionActionRunning.value = ''
        })
}

function clickedShowAll() {
    if (computedTherapy.value?.activeSession && !activeSession.value) {
        activeSession.value = computedTherapy.value.activeSession
        startTimer()
    }

    showAll.value = !showAll.value
}

async function clickedResponse(response) {
    request.value.responding = true
    await axios.post(route('requests.respond', { requestId: props.pendingRequest.id }), { response })
        .then((res) => {
            console.log(res)

            request.value.status = res.data.request.status
            if (res.data.request?.status !== response.toUpperCase() && response == 'accepted') {
                setSuccessAlertData({
                    time: 5000,
                    message: res.data.request.type == RequestTypes.therapy ? 'Your response was successful, but another counsellor may have already accepted to assist.' : ''
                })
                return
            }

            if (res.data.request?.status == 'ACCEPTED')
                counsellor.value = res.data.request.to

            setSuccessAlertData({
                time: 5000,
                message: 'You have successful responded to the request.'
            })
        })
        .catch((err) => {
            console.log(err)

            setFailedAlertData({
                time: 5000,
                message: 'Something unfortunate happened. Please try again shortly.'
            })
        })
    request.value.responding = false
}

</script>

<template>
    <Head :title="'Therapy' + (computedTherapy ? ` . ${computedTherapy.name}` : '')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-start items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight capitalize">{{ computedTherapy.name }}</h2>
                <div class="ml-2 lowercase text-sm"> . {{ toDiffForHumans(computedTherapy.createdAt) }}</div>
            </div>
        </template>

        <div 
            class="w-full sticky top-0 z-10 pt-2"
            :class="{'p-2 bg-white': showAll}"
            v-if="computedIsParticipant && (activeSession?.status !== 'HELD' && computedTherapy.activeSession?.status !== 'HELD')"
        >
            <div
                v-if="activeSession || computedTherapy.activeSession"
                class="w-[80%] md:w-[70%] lg:w-[60%] mb-2 mx-auto">
            
                <div
                    @click="clickedShowAll" 
                    class="p-2 bg-green-300 w-fit ml-auto rounded-lg text-green-800 text-center cursor-pointer"
                >{{ showAll ? 'hide session information' : 'show session information'}}</div>
            </div>
            <template v-if="showAll">
                <div
                    class="p-2 w-[80%] md:w-[70%] lg:w-[60%] mx-auto rounded-lg select-none cursor-pointer"
                    :class="[activeSession?.status == 'ABANDONED' ? 'bg-red-300' : 'bg-green-300']"
                    @dblclick="clickedActiveSession"
                    v-if="activeSession"
                >
                    <div
                        class="flex justify-end items-center text-sm space-x-2 font-bold"
                        v-if="activeSession?.status !== 'ABANDONED'"
                    >
                        <div class="text-blue-600 p-2 rounded bg-blue-200" v-if="timer.beforeStart > 0">{{ timer.beforeStart }} minutes to start</div>
                        <div class="text-green-800 p-2 rounded bg-green-200" v-if="timer.beforeEnd > 0">{{ timer.beforeEnd }} minutes to end</div>
                        <div class="text-gray-600 p-2 rounded bg-gray-200" v-if="timer.beforeEnd < 0">{{ timer.beforeEnd }} minutes beyond end time</div>
                    </div>
                    <div v-else class="text-red-600 p-2 rounded bg-red-200 w-fit ml-auto">abandoned</div>
                    <div 
                        class="my-2 mx-auto w-[90%] text-center"
                        :class="[activeSession?.status == 'ABANDONED' ? 'text-red-800' : 'text-green-800']"
                    >{{ activeSession.name }}</div>
                </div>
                <div 
                    v-if="onlineParticipants.length > 1"
                    class="p-2 text-center mt-2 text-gray-600 select-none font-bold text-sm w-[80%] md:w-[70%] lg:w-[60%] mx-auto rounded-lg bg-slate-300"
                >{{ onlineParticipants.find((u) => u.id !== userId).name }} is online</div>
            </template>

            <div
                v-if="sessionActionRunning"
                class="p-2 bg-green-300 w-[80%] md:w-[70%] lg:w-[60%] mt-2 mx-auto rounded-lg text-green-800 text-center">{{ sessionActionRunning }}</div>
        </div>

        <div class="pt-6 pb-12">

            <div class="bg-gray-100 z-[2] top-0 max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 p-2 flex justify-start items-center overflow-x-auto overflow-hidden">
                <div
                    v-for="item in scrollItems.filter(item => computedTherapy.paymentType == 'PAID' ? item : item.name != 'therapy_payment_details')"
                    :key="item.id"
                    @click="() => {
                        scrollToItem(item)
                    }"
                    class="py-1 px-2 cursor-pointer text-sm mr-2 text-center text-gray-600 border-b-2 transition duration-75 hover:border-gray-400"
                    :class="[activeItemId == item.id ? 'border-gray-600' : 'border-transparent']"
                >
                    {{ item.name }}
                </div>
            </div>
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="overflow-hidden shadow-sm sm:rounded-lg my-8 flex flex-col space-y-4 md:space-y-0 md:grid md:grid-cols-2 md:space-x-4">
                    
                    <div ref="mainDiv" class="flex space-x-4 items-start justify-start w-full overflow-hidden overflow-x-auto pb-2">

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_background_story">
                            <div class="text-gray-600 tracking-wide font-semibold">Background Story</div>
                            <div 
                                class="my-4 min-h-40 max-h-[500px] overflow-hidden overflow-y-auto text-sm"
                                :class="[computedTherapy.backgroundStory ? 'text-gray-700 text-justify' : 'flex justify-center items-center text-gray-600']"
                            >
                                {{ computedTherapy.backgroundStory ?? 'no background story' }}
                            </div>
                        </div>

                        <div class="w-full shrink-0" id="therapy_participants">
                            <div class="bg-white p-6 w-full">
                                <div class="text-gray-600 tracking-wide font-semibold">Counsellor</div>
                                <div v-if="counsellor" class="my-4">
                                    <CounsellorComponent
                                        :counsellor="counsellor"
                                        :has-view="false"
                                        :visit-page="!computedIsCounsellor"
                                    />
                                </div>
                                <div v-else-if="pendingRequest" class="relative">
                                    <FormLoader :show="request.responding" :text="'responding'"/>
                                    <div
                                        class="text-center text-sm text-gray-600 w-full"
                                        v-if="pendingRequest.from.isCounsellor && pendingRequest.from.userId == $page.props.auth.user?.id">you have already sent a request to assist.</div>
                                    
                                    <template v-else>

                                        <div
                                            class="text-center text-sm text-gray-600 w-full">you have a pending request to assist.</div>

                                        <div class="flex justify-end items-center p-2 overflow-hidden overflow-x-auto space-x-2">
                                            <PrimaryButton :disabled="request.responding" @click="() => clickedResponse('accepted')" class="shrink-0">accept</PrimaryButton>
                                            <DangerButton :disabled="request.responding" @click="() => clickedResponse('rejected')" class="shrink-0">reject</DangerButton>
                                        </div> 
                                    </template>
                                </div>
                                <div v-else class="my-4 flex justify-center items-start flex-col">
                                    <PrimaryButton
                                        @click="clickedAssistanceRequest"
                                        v-if="computedCanParticipate">{{!computedIsUser ? 'request to assist' : 'send assistance request'}}</PrimaryButton>
                                    <div class="mt-2 text-sm text-center p-2 text-gray-600">no counsellor yet</div>
                                </div>
                            </div>

                            <div class="bg-white p-6 shrink-0 mt-4 w-full">
                                <div class="text-gray-600 tracking-wide font-semibold">User</div>
                                <div class="my-4" v-if="computedTherapy.user">
                                    <UserComponent
                                        :user="computedTherapy.user"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_details">
                            <div class="text-gray-600 tracking-wide font-semibold">Details</div>
                            <div class="my-4">
                                <NameAndValue :name="'Payment Type'" :value="computedTherapy.paymentType"/>
                                <NameAndValue :name="'Session Type'" :value="computedTherapy.sessionType"/>
                                <NameAndValue :name="'Status'" :value="computedTherapy.status"/>

                                <hr class="my-4">
                                <div class="mb-2 mt-4 text-sm font-semibold tracking-wide">Cases</div>
                                <div class="flex p-2 items-center justify-start">
                                    <template v-if="computedTherapy.cases?.length">
                                        <div
                                            v-for="l in computedTherapy.cases"
                                            :key="l.id"
                                            :title="l.about ?? ''"
                                            class="capitalize mr-3 rounded text-sm w-fit p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                                        >{{ l.name }}</div>
                                    </template>
                                    <div v-else class="text-gray-600 text-sm text-center my-2">there are no therapy cases added.</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" v-if="computedTherapy.paymentType == 'PAID'" id="therapy_payment_details">
                            <div class="text-gray-600 tracking-wide font-semibold capitalize">Payment per {{ computedTherapy.per }}</div>
                            <div class="my-4">
                                <div class="flex justify-start items-center mb-4">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end">Online Amount:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[120px]">{{ computedTherapy.paymentData.currency + ' ' + computedTherapy.paymentData.amount }}</div>
                                </div>
                                <div class="flex justify-start items-center mb-4" v-if="computedTherapy.allowInPerson">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end">In-person Amount:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[120px]">{{ computedTherapy.paymentData.currency + ' ' + computedTherapy.paymentData.inPersonAmount ?? computedTherapy.paymentData.amount }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_other_details">
                            <div class="text-gray-600 tracking-wide font-semibold">Other Details</div>
                            <div class="text-gray-600 text-sm mt-2">green means true and otherwise false</div>
                            <div class="my-4">
                                <BooleanAttribute
                                    :text="'anonymous'"
                                    :condition="computedTherapy.anonymous"
                                    class="my-2"
                                />
                                <BooleanAttribute
                                    :text="'allow in person session'"
                                    :condition="computedTherapy.allowInPerson"
                                    class="my-2"
                                />
                                <BooleanAttribute
                                    :text="'is public'"
                                    :condition="computedTherapy.public"
                                    class="my-2"
                                />
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_stats">
                            <div class="text-gray-600 tracking-wide font-semibold">Stats</div>
                            <div class="my-4">
                                <ActivityBadge
                                    :name="'maximum allowed sessions'"
                                    :value="computedTherapy.maxSessions"
                                    class="my-2"
                                />
                                <ActivityBadge
                                    :name="'total sessions'"
                                    :value="computedTherapy.sessionsCreated"
                                    class="my-2 ml-6 mt-4"
                                />
                                <ActivityBadge
                                    :name="'held sessions'"
                                    :value="computedTherapy.sessionsHeld"
                                    class="my-2 mt-4"
                                />
                                <ActivityBadge
                                    :name="'free sessions held'"
                                    :value="computedTherapy.freeSessions"
                                    class="my-2 mt-4 ml-6"
                                />
                                <ActivityBadge
                                    :name="'paid sessions held'"
                                    :value="computedTherapy.paidSessions"
                                    class="my-2 mt-4"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded">
                        <TherapyComponent
                            :therapy="computedTherapy"
                            :newSession="newSession"
                            :activeSession="activeSession"
                            :selectedActiveSession="selectedActiveSession"
                            :is-participant="computedIsParticipant"
                            :is-user="computedIsUser"
                            :is-counsellor="computedIsCounsellor"
                            @deselect-active-session="() => selectedActiveSession = false"
                            @update-active-session="(data) => activeSession = data"
                        />
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8" v-if="computedIsParticipant">
                <div class="p-6 bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">Actions</div>

                    <div class="flex space-x-2 justify-start items-center w-full overflow-hidden overflow-x-auto p-2">
                        <PrimaryButton @click="clickedReport" class="shrink-0" v-if="$page.props.auth.user">make a report</PrimaryButton>
                        <template v-if="computedTherapy.status !== 'ENDED'">
                            <PrimaryButton @click="clickedCreateSession" class="shrink-0" v-if="computedIsCounsellor && computedTherapy.maxSessions > computedTherapy.sessionsHeld">create session</PrimaryButton>
                            <template v-if="!computedIsInSession">
                                <PrimaryButton @click="clickedEndTherapy" v-if="computedIsParticipant && computedTherapy.status !== 'ENDED' && computedTherapy.sessionsHeld" class="shrink-0">end therapy</PrimaryButton>
                                <template v-if="computedIsUser">
                                    <PrimaryButton @click="clickedUpdate" class="shrink-0">update therapy</PrimaryButton>
                                    <DangerButton @click="clickedDelete" class="shrink-0">delete therapy</DangerButton>
                                </template>
                            </template>
                            <template v-if="activeSession && activeSession?.status !== 'ABANDONED'">
                                <PrimaryButton
                                    :disabled="!!sessionActionRunning"
                                    v-if="(['PENDING', 'IN_SESSION_CONFIRMATION'].includes(activeSession?.status) && userId !== activeSession?.updatedById) && activeSession?.status !== 'IN_SESSION' && timer.beforeEnd > 0"
                                    @click="clickedStartSession" class="shrink-0">start session for you</PrimaryButton>
                                <PrimaryButton
                                    :disabled="!!sessionActionRunning"
                                    v-if="['PENDING', 'IN_SESSION', 'IN_SESSION_CONFIRMATION'].includes(activeSession?.status) && timer.beforeEnd > 0 && computedIsInSession"
                                    @click="clickedAbandonSession" class="shrink-0">abondon session</PrimaryButton>
                                <PrimaryButton
                                    :disabled="!!sessionActionRunning"
                                    v-if="computedIsInSession && timer.beforeEnd < 0 && userId !== activeSession?.updatedById"
                                    @click="clickedEndSession" class="shrink-0">end session for you</PrimaryButton>
                            </template>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <MiniModal
        :show="modalData.show && ['request assistance', 'delete', 'end'].includes(modalData.type)"
        @close="closeModal"
    >
        <div class="select-none">
            <template v-if="modalData.type == 'request assistance'">
                <div class="text-gray-600 text-center font-bold tracking-wide">
                    Assistance Request
                </div>

                <hr class="my-2">

                <div class="relative">
                    <FormLoader
                        :text="loader.type == 'assistance' ? 
                            (computedIsUser ? 'requesting assistance' : 'requesting to assist')
                            : 'getting counsellors'
                        "
                        :show="loader.show && ['assistance', 'counsellors'].includes(loader.type)"
                    />

                    <div v-if="computedIsUser" class="overflow-hidden overflow-y-auto h-[60vh]">
                        <div class="w-[90%] mx-auto text-sm text-center text-gray-600 my-2">Search counsellors, select (<em>by double clicking</em>) and send request. You can send the request to multiple counsellors.</div>
                        <div class="my-2 mx-auto w-[90%]">
                            <TextInput
                                v-model="counsellorSearch"
                                placeholder="search for counsellor"
                                class="w-full"
                            />
                        </div>

                        <div class="p-2 flex items-center space-x-4 justify-start overflow-hidden overflow-x-auto">
                            <template v-if="searchedCounsellors.length">

                                <CounsellorComponent 
                                    v-for="(counsellor, idx) in searchedCounsellors"
                                    :counsellor="counsellor"
                                    :key="idx"
                                    :has-view="false"
                                    :visit-page="false"
                                    :for-request="true"
                                    title="double click to select"
                                    @dblclick="() => {
                                        addToSelected(counsellor)
                                    }"
                                    class="shrink-0"
                                />
                                <div 
                                    v-if="page !== 0 && searchedCounsellors.length"
                                    class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                                    @click="() => debouncedGetCounsellors()"
                                    title="get more counsellors"
                                >...</div>
                            </template>

                            <div v-else class="my-2 text-center text-sm w-full text-gray-600">no searched counsellor</div>
                        </div>

                        <div class="text-sm text-gray-600 font-semibold mb-2 mt-4">Selected Counsellors</div>

                        <div class="p-2 flex items-center justify-start overflow-hidden overflow-x-auto">
                            <template v-if="selectedCounsellors.length">

                                <CounsellorComponent 
                                    v-for="(counsellor, idx) in selectedCounsellors"
                                    :counsellor="counsellor"
                                    :key="idx"
                                    :has-view="false"
                                    @click="() => {
                                        removeFromSelected(counsellor)
                                    }"
                                />
                            </template>
                            <div v-else class="my-2 text-center text-sm w-full text-gray-600">no selected counsellor</div>
                        </div>

                    </div>

                    <div v-else>
                        <UserComponent :user="computedTherapy.user"/>

                        <div class="w-[90%] mx-auto text-sm text-center text-gray-600">You are sending a request to assist {{ computedTherapy?.user.fullName }} with this therapy.</div>

                    </div>

                    <div class="flex justify-end mt-4 ">
                        <PrimaryButton :disabled="!(computedIsUser && selectedCounsellors.length) && (!$page.props.auth.user?.counsellor)" @click="sendAssistanceRequest">send request</PrimaryButton>
                    </div>
                </div>
            </template>
            
            <template v-if="modalData.type == 'delete'">
                <div class="text-gray-600 text-center font-bold tracking-wide">
                    Delete Therapy
                </div>

                <hr class="my-2">

                <div class="relative">
                    <FormLoader
                        :text="'deleting therapy'"
                        :show="loader.show"
                        :danger="true"
                    />

                    <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                        Are you sure you want to delete this therapy?
                    </div>
                </div>

                <div class="flex space-x-2 justify-end items-center w-full p-2">
                    <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                    <DangerButton @click="deleteTherapy" class="shrink-0">delete</DangerButton>
                </div>
            </template>
            
            <template v-if="modalData.type == 'end'">
                <div class="text-gray-600 text-center font-bold tracking-wide">
                    End Therapy
                </div>

                <hr class="my-2">

                <div class="relative">
                    <FormLoader
                        :text="'ending therapy'"
                        :show="loader.show"
                    />

                    <div class="my-4 text-sm text-gray-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                        Are you sure you want to end this therapy?
                    </div>
                </div>

                <div class="flex space-x-2 justify-end items-center w-full p-2">
                    <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                    <DangerButton @click="endTherapy" class="shrink-0">end</DangerButton>
                </div>
            </template>
        </div>
    </MiniModal>
        
    <UpdateIndividualTherapyFormModal
        :show="modalData.show && modalData.type == 'update'"
        :therapy="computedTherapy"
        @close-modal="closeModal"
    />
        
    <CreateSessionFormModal
        :show="modalData.show && modalData.type == 'create session'"
        :therapy="computedTherapy"
        @close-modal="closeModal"
        @on-success="(data) => {
            if (data) newSession = data
        }"
    />
        
    <CreateReportModal
        :show="modalData.show && modalData.type == 'report'"
        :item="computedTherapy"
        :type="'Therapy'"
        @close-modal="closeModal"
        @on-success="() => reportCreated(report)"
    />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>