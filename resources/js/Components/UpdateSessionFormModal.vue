<script setup>
import { ref, watch } from 'vue';
import useScheduleTimes from '@/Composables/useScheduleTimes'
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import InPersonPlacePicker from '@/Components/InPersonPlacePicker.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import TopicSection from "./TopicSection.vue";
import Select from "./Select.vue";
import useErrorHandler from '@/Composables/useErrorHandler';
import PreferenceItem from './PreferenceItem.vue';

const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()
const { setErrorData, clearErrorData } = useErrorHandler()

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    therapy: {
        default: null,
    },
    session: {
        default: null,
    },
    loadedTopics: {
        default: []
    },
    loadedTopicsPage: {
        default: 0
    },
})

const emits = defineEmits(['close', 'onUpdate'])

const loading = ref(false)
// SCRUM-379: shared start/end handling (resources/js/Composables/useScheduleTimes.js).
const { startTime, endTime, setTimes, clearTimes, timesError, computedStartTime, computedEndTime, computedNow } = useScheduleTimes({ isOpen: () => props.show })
const selectedCases = ref([])
const selectedTopics = ref([])
const sessionData = ref({
    'name': '',
    'about': '',
    'startTime': '',
    'endTime': '',
    'lng': '',
    'lat': '',
    'landmark': '',
    'type': '',
    'paymentType': '',
    'cases': [],
    'topics': [],
})
const sessionErrors = ref({
    'name': '',
    'about': '',
    'startTime': '',
    'endTime': '',
    'lng': '',
    'lat': '',
    'landmark': '',
    'type': '',
    'paymentType': '',
    'cases': '',
    'topics': '',
})

watch(() => props.show, () => {
    if (!props.show) return

    setSessionFormData()
})
watch(() => props.therapy.allowInPerson, () => {
    if (props.therapy.allowInPerson)
        sessionData.value.type = 'ONLINE'
})
watch(() => props.therapy.paymentType, () => {
    if (props.therapy.paymentType == 'FREE')
        sessionData.value.type = 'FREE'
})
watch(() => props.therapy.cases?.length, () => {
    if (props.therapy.cases?.length)
        sessionData.value.cases = props.therapy.cases.map((c) => c.id)
})


async function updateSession() {
    if (!sessionData.value.name) {
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

    sessionData.value.topics = [...selectedTopics.value.map((c) => c.id)]
    sessionData.value.cases = [...selectedCases.value.map((c) => c.id)]

    sessionData.value.startTime = new Date(startTime.value).toISOString()
    sessionData.value.endTime = new Date(endTime.value).toISOString()

    loading.value = true

    await axios.patch(route(`api.sessions.update`, { sessionId: props.session.id }), {...sessionData.value})
        .then((res) => {
            
            setAlertData({
                message: 'Your session has been successfully updated.',
                type: 'success',
                show: true,
            })
            emits('onUpdate', res.data.session)
            closeModal()
        })
        .catch((err) => {
            console.log(err, err.response?.data?.errors)
            if (err.response?.data?.errors) {
                setErrorData(sessionErrors, err.response.data.errors, [
                    'name', 'about', 'startTime', 'endTime', 'lng', 'lat', 'landmark', 'type',
                    'paymentType', 'cases'
                ])
                setFailedAlertData({
                    message: 'There has been a validation error. Please check your form.',
                })
                return
            }

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
        .finally(() => {
            loading.value = false
        })
}

function clearData() {
    sessionData.value.name = ''
    sessionData.value.about = ''
    sessionData.value.paymentType = ''
    sessionData.value.lng = ''
    sessionData.value.lat = ''
    sessionData.value.landmark = ''
    sessionData.value.startTime = ''
    clearTimes()
    sessionData.value.endTime = ''
    sessionData.value.type = ''
    sessionData.value.cases = []
    selectedCases.value = []
}

function setSessionFormData() {
    sessionData.value.name = props.session.name
    sessionData.value.about = props.session.about
    sessionData.value.paymentType = props.session.paymentType
    sessionData.value.startTime = props.session.startTime
    setTimes(props.session.startTime, props.session.endTime)
    sessionData.value.endTime = props.session.endTime
    sessionData.value.type = props.session.type
    sessionData.value.lng = props.session.lng
    sessionData.value.lat = props.session.lat
    sessionData.value.landmark = props.session.landmark
    sessionData.value.cases = props.session.cases?.map((c) => c.id)
    selectedCases.value = props.session.cases
    sessionData.value.topics = props.session.topics?.map((c) => c.id)
    selectedTopics.value = props.session.topics
}

function closeModal() {
    clearData()
    clearErrorData(sessionErrors, [
        'name', 'about', 'startTime', 'endTime', 'lng', 'lat', 'landmark', 'type',
        'paymentType', 'cases'
    ])
    emits('close')
}

function addCaseToSelected(newCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== newCase.id), newCase]
}

function removeCaseFromSelected(oldCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== oldCase.id)]
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
        v-bind="$attrs"
    >
        <div class="select-none relative">

            <div class="p-4 w-full mt-2 mb-4">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Session</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`updating session`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="updateSession"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="name" value="Name" />

                                <TextInput
                                    id="name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="sessionData.name"
                                    required
                                    autofocus
                                />
                                
                                <InputError class="mt-2" :message="sessionErrors.name" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="about" value="About" />

                                <TextBox
                                    id="about"
                                    class="mt-1 block w-full"
                                    v-model="sessionData.about"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives the user an idea about what this session will be.</div>
                                <InputError class="mt-2" :message="sessionErrors.about" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapy.allowInPerson">
                                <InputLabel for="type" value="Type" />

                                <Select
                                    id="type"
                                    class="mt-1 block w-full"
                                    v-model="sessionData.type"
                                    autocomplete="type"
                                    :options="['ONLINE', {value: 'IN_PERSON', name: 'in person'}]"
                                    :default-option="'select type'"
                                    required
                                />

                                <InputError class="mt-2" :message="sessionErrors.type" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapy.paymentType == 'PAID'">
                                <InputLabel for="paymentType" value="Payment Type" />

                                <Select
                                    id="paymentType"
                                    class="mt-1 block w-full"
                                    v-model="sessionData.paymentType"
                                    autocomplete="paymentType"
                                    :options="['free', 'paid']"
                                    :default-option="'select payment type'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500">If paid is selected, payment will be made before session is activated.</div>
                                <InputError class="mt-2" :message="sessionErrors.paymentType" />
                            </div>
                        </div>

                        <div class="p-4 rounded bg-gray-200 shadow-sm my-4" v-if="therapy.cases?.length">
                            <div class="text-sm text-center mb-2 font-bold">Select case(s) pertaining to session</div>
                            <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
                                <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto">
                                    <PreferenceItem
                                        v-for="c in therapy.cases"
                                        :key="c.id"
                                        :item="c"
                                        @select-item="(data) => addCaseToSelected(data)"
                                    />
                                </div>
                            </div>

                            <div class="text-sm text-center mb-2 font-bold">Selected cases</div>
                            <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                                
                                <template v-if="selectedCases?.length">
                                    <div
                                        v-for="c in selectedCases"
                                        :key="c.id"
                                        class="capitalize mr-3 rounded relative text-sm w-fit p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                                    >
                                        {{ c.name }}
                                        <div 
                                            @click="() => removeCaseFromSelected(c)"
                                            :title="`remove ${c.name}`"
                                            class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                                                border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                                        >x</div>
                                    </div>
                                </template>
                                <div v-else class="w-full text-center text-sm text-gray-600">no selected cases</div>
                            </div>
                        </div>

                        <div class="p-4 rounded bg-gray-200 shadow-sm my-4">
                            <TopicSection
                                :loaded-topics="loadedTopics"
                                :loaded-topics-page="loadedTopicsPage"
                                :selected-topics="session.topics"
                                :therapy="therapy"
                                @on-data="(data) => {
                                    if (data) selectedTopics = [...data]
                                }"
                            />

                            <InputError class="mt-2" :message="sessionErrors.topics" />
                        </div>

                        <div class="my-4 p-4 bg-gray-200 rounded shadow-sm" v-if="therapy.allowInPerson && sessionData.type == 'IN_PERSON'">
                            <div class="font-bold text-start text-gray-600 capitalize my-2">location information</div>
                            <InPersonPlacePicker
                                class="mt-2 mb-4 p-2"
                                map-id="sessionUpdatingMap"
                                v-model:lat="sessionData.lat"
                                v-model:lng="sessionData.lng"
                                v-model:landmark="sessionData.landmark"
                                :errors="sessionErrors"
                                :anonymous="!!therapy?.anonymous"
                                :initial-place="session?.type === 'IN_PERSON' ? { lat: session.lat, lng: session.lng } : null"
                            />
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
                                <InputError class="mt-2" :message="sessionErrors.startTime" />
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
                                <InputError class="mt-2" :message="sessionErrors.endTime" />
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