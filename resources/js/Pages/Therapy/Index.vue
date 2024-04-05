<script setup>
import BooleanAttribute from '@/Components/BooleanAttribute.vue';
import CounsellorComponent from '@/Components/CounsellorComponent.vue';
import ActivityBadge from '@/Components/ActivityBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import UserComponent from '@/Components/UserComponent.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue';
import DangerButton from '@/Components/DangerButton.vue';
import NameAndValue from '@/Components/NameAndValue.vue';
import TherapyComponent from '@/Components/TherapyComponent.vue';
import MiniModal from '@/Components/MiniModal.vue';
import useModal from '@/Composables/useModal';
import TextInput from '../TextInput.vue';
import FormLoader from '@/Components/FormLoader.vue';
import { default as _ } from 'lodash';
import useAlert from '@/Composables/useAlert';
import Alert from '@/Components/Alert.vue';
import UpdateIndividualTherapyFormModal from '@/Components/UpdateIndividualTherapyFormModal.vue';
import CreateSessionFormModal from '@/Components/CreateSessionFormModal.vue';

const { modalData, showModal, closeModal } = useModal()
const { alertData, clearAlertData, setAlertData } = useAlert()

const props = defineProps({
    therapy: {
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

const loader = ref({
    show: false,
    type: ''
})
const counsellorSearch = ref('')
const mainDiv = ref(null)
const newSession = ref(null)
const activeItemId = ref(scrollItems[0].id)
const searchedCounsellors = ref([])
const selectedCounsellors = ref([])
const page = ref(1)
const therapyForm = useForm({})

watch(() => counsellorSearch.value, () => {
    if (counsellorSearch.value)
        debouncedGetCounsellors()
})

const computedIsUser = computed(() => {
    return usePage().props.auth.user?.id == props.therapy.user?.id
})
const computedIsCounsellor = computed(() => {
    return usePage().props.auth.user?.id == props.therapy.counsellor?.userId
})
const computedIsParticipant = computed(() => {
    return computedIsUser.value || computedIsCounsellor.value
})
const computedCanParticipate = computed(() => {
    const user = usePage().props.auth.user

    if (user?.id == props.therapy.user?.id || user?.counsellor) return true

    return false
})

function clickedAssistanceRequest() {
    showModal('request assistance')
}

function clearData() {
    searchedCounsellors.value = []
    selectedCounsellors.value = []
    counsellorSearch.value = ''
    page.value = 1
}
 
async function deleteTherapy() {
    therapyForm.delete(route(`therapies.delete`, { therapyId: props.therapy.id }), {
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
    therapyForm.post(route(`therapies.end`, { therapyId: props.therapy.id }), {
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
</script>

<template>
    <Head :title="'Therapy' + (therapy ? ` . ${therapy.name}` : '')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-start items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight capitalize">{{ therapy.name }}</h2>
                <div class="ml-2 lowercase text-sm"> . {{ therapy.createdAt }}</div>
            </div>
        </template>

        <div class="pt-6 pb-12">

            <div class="bg-gray-100 z-[2] top-0 max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 p-2 flex justify-start items-center overflow-x-auto overflow-hidden">
                <div
                    v-for="item in scrollItems.filter(item => therapy.paymentType == 'PAID' ? item : item.name != 'therapy_payment_details')"
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
                                :class="[therapy.backgroundStory ? 'text-gray-700 text-justify' : 'flex justify-center items-center text-gray-600']"
                            >
                                {{ therapy.backgroundStory ?? 'no background story' }}
                            </div>
                        </div>

                        <div class="w-full shrink-0" id="therapy_participants">
                            <div class="bg-white p-6 w-full">
                                <div class="text-gray-600 tracking-wide font-semibold">Counsellor</div>
                                <div v-if="therapy.counsellor" class="my-4">
                                    <CounsellorComponent
                                        :counsellor="therapy.counsellor"
                                        :has-view="false"
                                        :visit-page="!computedIsCounsellor"
                                    />
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
                                <div class="my-4" v-if="therapy.user">
                                    <UserComponent
                                        :user="therapy.user"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_details">
                            <div class="text-gray-600 tracking-wide font-semibold">Details</div>
                            <div class="my-4">
                                <NameAndValue :name="'Payment Type'" :value="therapy.paymentType"/>
                                <NameAndValue :name="'Session Type'" :value="therapy.sessionType"/>
                                <NameAndValue :name="'Status'" :value="therapy.status"/>

                                <hr class="my-4">
                                <div class="mb-2 mt-4 text-sm font-semibold tracking-wide">Cases</div>
                                <div class="flex p-2 items-center justify-start">
                                    <template v-if="therapy.cases?.length">
                                        <div
                                            v-for="l in therapy.cases"
                                            :key="l.id"
                                            :title="l.about ?? ''"
                                            class="capitalize mr-3 rounded text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                                        >{{ l.name }}</div>
                                    </template>
                                    <div v-else class="text-gray-600 text-sm text-center my-2">there are no therapy cases added.</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" v-if="therapy.paymentType == 'PAID'" id="therapy_payment_details">
                            <div class="text-gray-600 tracking-wide font-semibold capitalize">Payment per {{ therapy.per }}</div>
                            <div class="my-4">
                                <div class="flex justify-start items-center mb-4">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end">Online Amount:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[120px]">{{ therapy.paymentData.currency + ' ' + therapy.paymentData.amount }}</div>
                                </div>
                                <div class="flex justify-start items-center mb-4" v-if="therapy.allowInPerson">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[130px] text-end">In-person Amount:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[120px]">{{ therapy.paymentData.currency + ' ' + therapy.paymentData.inPersonAmount ?? therapy.paymentData.amount }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_other_details">
                            <div class="text-gray-600 tracking-wide font-semibold">Other Details</div>
                            <div class="text-gray-600 text-sm mt-2">green means true and otherwise false</div>
                            <div class="my-4">
                                <BooleanAttribute
                                    :text="'anonymous'"
                                    :condition="therapy.anonymous"
                                    class="my-2"
                                />
                                <BooleanAttribute
                                    :text="'allow in person session'"
                                    :condition="therapy.allowInPerson"
                                    class="my-2"
                                />
                                <BooleanAttribute
                                    :text="'is public'"
                                    :condition="therapy.public"
                                    class="my-2"
                                />
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full" id="therapy_stats">
                            <div class="text-gray-600 tracking-wide font-semibold">Stats</div>
                            <div class="my-4">
                                <ActivityBadge
                                    :name="'maximum allowed sessions'"
                                    :value="therapy.maxSessions"
                                    class="my-2"
                                />
                                <ActivityBadge
                                    :name="'total sessions'"
                                    :value="therapy.sessionsCreated"
                                    class="my-2 ml-6 mt-4"
                                />
                                <ActivityBadge
                                    :name="'held sessions'"
                                    :value="therapy.sessionsHeld"
                                    class="my-2 mt-4"
                                />
                                <ActivityBadge
                                    :name="'free sessions held'"
                                    :value="therapy.freeSessions"
                                    class="my-2 mt-4 ml-6"
                                />
                                <ActivityBadge
                                    :name="'paid sessions held'"
                                    :value="therapy.paidSessions"
                                    class="my-2 mt-4"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded">
                        <TherapyComponent
                            :therapy="therapy"
                            :newSession="newSession"
                            :is-participant="computedIsParticipant"
                        />
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8" v-if="computedIsParticipant">
                <div class="p-6 bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">Actions</div>

                    <div class="flex space-x-2 justify-start items-center w-full overflow-hidden overflow-x-auto p-2">
                        <PrimaryButton @click="clickedReport" class="shrink-0" v-if="$page.props.auth.user">report</PrimaryButton>
                        <template v-if="therapy.status !== 'ENDED'">
                            <PrimaryButton @click="clickedCreateSession" class="shrink-0" v-if="computedIsCounsellor && therapy.maxSessions > therapy.sessionsHeld">create session</PrimaryButton>
                            <PrimaryButton @click="clickedEndTherapy" v-if="therapy.status !== 'ENDED' && therapy.sessionsHeld" class="shrink-0">end therapy</PrimaryButton>
                            <template v-if="computedIsUser">
                                <PrimaryButton @click="clickedUpdate" class="shrink-0">update therapy</PrimaryButton>
                                <DangerButton @click="clickedDelete" class="shrink-0">delete therapy</DangerButton>
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

                        <div class="p-2 flex items-center space-x-2 justify-start overflow-hidden overflow-x-auto">
                            <template v-if="searchedCounsellors.length">

                                <CounsellorComponent 
                                    v-for="(counsellor, idx) in searchedCounsellors"
                                    :counsellor="counsellor"
                                    :key="idx"
                                    :has-view="false"
                                    :for-request="true"
                                    title="double click to select"
                                    @dblclick="() => {
                                        addToSelected(counsellor)
                                    }"
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
                        <UserComponent :user="therapy.user"/>

                        <div class="w-[90%] mx-auto text-sm text-center text-gray-600">You are sending a request to assist {{ therapy?.user.fullName }} with this therapy.</div>

                    </div>

                    <div class="flex justify-end mt-4 ">
                        <PrimaryButton :disabled="computedIsUser && !selectedCounsellors.length" @click="sendAssistanceRequest">send request</PrimaryButton>
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
                        :text="'deleting therapy...'"
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
                        :text="'ending therapy...'"
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
        :therapy="therapy"
        @close-modal="closeModal"
    />
        
    <CreateSessionFormModal
        :show="modalData.show && modalData.type == 'create session'"
        :therapy="therapy"
        @close-modal="closeModal"
        @on-success="(data) => {
            if (data) newSession = data
        }"
    />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>