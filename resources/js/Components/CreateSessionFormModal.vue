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
import useMap from "@/Composables/useMap";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Select from "./Select.vue";
import { useForm } from '@inertiajs/vue3';
import { addMinutes, format, differenceInMinutes } from 'date-fns';
import PreferenceItem from './PreferenceItem.vue';

const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()
const { getCurrentLocation, currentLocation } = useLocation()
const { initMap, createMap, mapDetails, addMarker, map, markerPosition } = useMap()

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    therapy: {
        default: null,
    }
})

const emits = defineEmits(['closeModal', 'onSuccess'])

const sessionCreationMap = ref(null)
const loading = ref(false)
const startTime = ref('')
const endTime = ref('')
const selectedCases = ref([])
const sessionForm = useForm({
    'name': '',
    'about': '',
    'startTime': '',
    'endTime': '',
    'lng': '',
    'lat': '',
    'landmark': '',
    'type': '',
    'paymentType': '',
    'cases': []
})

onBeforeMount(() => {
    startTime.value = getDefaultTime(30)
    endTime.value = getDefaultTime(70)
})

watchEffect(() => {

    if (!props.therapy.allowInPerson) return

    initMap()
    getCurrentLocation()
    
    if (mapDetails.value.Map && sessionCreationMap.value && props.therapy.allowInPerson && sessionForm.type == 'IN_PERSON')
        createMap(sessionCreationMap.value, currentLocation.value)
})
watch(() => markerPosition.value.lat || markerPosition.value.lng, () => {
    sessionForm.lat = markerPosition.value.lat ?? ''
    sessionForm.lng = markerPosition.value.lng ?? ''
})
watch(() => props.therapy.allowInPerson, () => {
    if (props.therapy.allowInPerson)
        sessionForm.type = 'ONLINE'
})
watch(() => props.therapy.paymentType, () => {
    if (props.therapy.paymentType == 'FREE')
        sessionForm.type = 'FREE'
})
watch(() => props.therapy.cases?.length, () => {
    if (props.therapy.cases?.length)
        sessionForm.cases = props.therapy.cases.map((c) => c.id)
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

async function createSession() {
    if (!sessionForm.name) {
        setAlertData({
            message: "Name is required for a session.",
            time: 5000,
            show: true,
            type: 'failed'
        });
        return
    }

    // start cannot be before 30 mins from now, end cannot be 30 mins before start
    if (!isMinutesBefore({ firstTime: startTime.value, minutes: 25 })) {
        setFailedAlertData({
            message: 'Start time must be at least 25 minutes away from current time. Please increase the start time.',
            time: 5000
        })
        return
    }
    
    if (!isMinutesBefore({ firstTime: endTime.value, secondTime: startTime.value, minutes: 30})) {
        setFailedAlertData({
            message: 'End time must be at least 30 minutes away from start time. Please increase the end time.',
            time: 5000
        })
        return
    }

    if (selectedCases.value.length)
        sessionForm.cases = [...selectedCases.value.map((c) => c.id)]

    sessionForm.startTime = new Date(startTime.value).toISOString()
    sessionForm.endTime = new Date(endTime.value).toISOString()

    if (props.therapy.paymentType == 'FREE' && !sessionForm.paymentType)
        sessionForm.paymentType = 'FREE'

    if (!props.therapy.allowInPerson && !sessionForm.type)
        sessionForm.type = 'ONLINE'

    sessionForm.post(route(`sessions.create`, { therapyId: props.therapy.id }), {
        onStart: () => {
            loading.value = true
            sessionForm.clearErrors()
            console.log(sessionForm.hasErrors)
        },
        onFinish: () => {
            loading.value = false
        },
        onError: (err) => {
            console.log(err)
            if (sessionForm.hasErrors && !err.alert) {
                const errKeys = Object.keys(err).join(', ')
                setFailedAlertData({
                    message: `You have errors regarding the following: '${errKeys}'. Please check the form again.`,
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

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 5000
            })
        },
        onSuccess: (res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your session has been successfully created.',
                type: 'success',
                show: true,
                time: 4000
            })
            if (res.props.session)
                emits('onSuccess', res.props.session)
            closeModal()
        }
    })
}

function clearData() {
    sessionForm.name = ''
    sessionForm.about = ''
    sessionForm.paymentType = ''
    sessionForm.lng = ''
    sessionForm.lat = ''
    sessionForm.landmark = ''
    sessionForm.startTime = ''
    startTime.value = ''
    endTime.value = ''
    sessionForm.endTime = ''
    sessionForm.type = ''
    sessionForm.cases = []
    selectedCases.value = []
}

function closeModal() {
    clearData()
    emits('closeModal')
}

function addCaseToSelected(newCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== newCase.id), newCase]
}

function removeCaseFromSelected(oldCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== oldCase.id)]
}

function useCurrentLocation() {
    getCurrentLocation()
    map.value.setCenter({
        lat: parseFloat(currentLocation.value.lat),
        lng: parseFloat(currentLocation.value.lng),
    })
    addMarker(unref(map), currentLocation.value)
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
                >Create Session</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`creating session`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="createSession"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="name" value="Name" />

                                <TextInput
                                    id="name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="sessionForm.name"
                                    required
                                    autofocus
                                />
                                
                                <InputError class="mt-2" :message="sessionForm.errors.name" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="about" value="About" />

                                <TextBox
                                    id="about"
                                    class="mt-1 block w-full"
                                    v-model="sessionForm.about"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives the user an idea about what this session will be.</div>
                                <InputError class="mt-2" :message="sessionForm.errors.about" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapy.allowInPerson">
                                <InputLabel for="type" value="Type" />

                                <Select
                                    id="type"
                                    class="mt-1 block w-full"
                                    v-model="sessionForm.type"
                                    autocomplete="type"
                                    :options="['ONLINE', {value: 'IN_PERSON', name: 'in person'}]"
                                    :default-option="'select type'"
                                    required
                                />

                                <InputError class="mt-2" :message="sessionForm.errors.sessionType" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapy.paymentType == 'PAID'">
                                <InputLabel for="paymentType" value="Payment Type" />

                                <Select
                                    id="paymentType"
                                    class="mt-1 block w-full"
                                    v-model="sessionForm.paymentType"
                                    autocomplete="paymentType"
                                    :options="['free', 'paid']"
                                    :default-option="'select payment type'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500">If paid is selected, payment will be made before session is activated.</div>
                                <InputError class="mt-2" :message="sessionForm.errors.paymentType" />
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
                                
                                <template v-if="selectedCases.length">
                                    <div
                                        v-for="c in selectedCases"
                                        :key="c.id"
                                        class="capitalize mr-3 rounded relative text-sm p-2 w-fit min-w-[100px] text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
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

                        <div class="my-4 p-4 bg-gray-200 rounded shadow-sm" v-if="therapy.allowInPerson && sessionForm.type == 'IN_PERSON'">
                            <div class="font-bold text-start text-gray-600 capitalize my-2">location information</div>
                            <div class="w-full flex flex-col mt-2 mb-4 p-2">
                                <div>
                                    <div class="text-gray-600 text-sm mb-2 text-center">Location data (We recommend you pick location on map by clicking anywhere on the map).</div>
                                    <div class="w-full h-[300px] bg-blue-200" ref="sessionCreationMap" id="sessionCreationMap"></div>
                                    <div class="flex justify-start items-center flex-col">
                                        <TextInput
                                            placeholder="longitude" 
                                            type="number"
                                            id="lng"
                                            name="lng"
                                            class="my-2 w-full"
                                            v-model="sessionForm.lng"
                                            disabled
                                            required
                                        ></TextInput>
                                        <InputError :message="sessionForm.errors.lng" class="mt-2" />

                                        <TextInput
                                            placeholder="latitude" 
                                            type="number"
                                            id="lat"
                                            name="lat"
                                            class="my-2 w-full"
                                            v-model="sessionForm.lat"
                                            disabled
                                            required
                                        ></TextInput>
                                        <InputError :message="sessionForm.errors.lat" class="mt-2" />
                                    <div class="flex justify-end w-full">
                                        <div 
                                        @click="useCurrentLocation"
                                        :class="[
                                            (currentLocation.lat == sessionForm.lat && currentLocation.lng == sessionForm.lng)
                                            ? 'bg-blue-700 text-blue-300' 
                                            : 'text-blue-700 bg-blue-300 hover:bg-blue-700 hover:text-blue-300'
                                        ]"
                                        class="mt-2 text-sm py-1 px-2 rounded w-fit float-right cursor-pointer transition duration-75"
                                        >current location</div>
                                    </div>

                                    <TextInput
                                        placeholder="landmark" 
                                        id="landmark"
                                        name="landmark"
                                        class="my-2 w-full"
                                        v-model="sessionForm.landmark"
                                    />
                                    
                                    <InputError :message="sessionForm.errors.landmark" class="mt-2" />
                                    </div>
                                </div>
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
                                <InputError class="mt-2" :message="sessionForm.errors.startTime" />
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
                                <InputError class="mt-2" :message="sessionForm.errors.endTime" />
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