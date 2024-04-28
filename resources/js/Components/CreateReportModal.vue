<script setup>
import { computed, onBeforeMount, ref, unref, watch, watchEffect } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Select from "./Select.vue";
import { usePage } from '@inertiajs/vue3';
import SessionBadge from './SessionBadge.vue';
import useAuth from '@/Composables/useAuth';
import useErrorHandler from '@/Composables/useErrorHandler';
import FilePreview from './FilePreview.vue';

const { goToLogin } = useAuth()
const { clearErrorData, setErrorData } = useErrorHandler()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const user = usePage().props.auth.user

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    item: {
        default: null,
    },
    type: {
        default: null,
    }
})

const emits = defineEmits(['closeModal', 'onSuccess'])

const filesInput = ref(null)
const reportableType = ref('')
const loading = ref(false)
const sessions = ref({
    data: [],
    page: 1,
})
const getting = ref({
    show: false,
    type: '',
})
const reportData = ref({
    'description': '',
    'data': [],
    'files': [],
    'reportableType': '',
    'reportableId': '',
    'addedbyType': 'User',
    'addedbyId': user ? user.id : '',
})
const reportErrors = ref({
    'description': '',
    'data': '',
    'files': '',
    'reportableType': '',
    'reportableId': '',
    'addedbyType': '',
    'addedbyId': '',
})

watch(() => reportableType.value, () => {
    reportData.value.reportableType = ''
    reportData.value.reportableId = ''

    if (reportableType.value == 'THERAPY') {
        reportData.value.reportableType = 'Therapy'
        reportData.value.reportableId = props.item.id
        return
    }
    
    if (reportableType.value == 'SESSION' && !sessions.value.data?.length) {
        getSessions()
        return
    }
})

const computedIsCounsellor = computed(() => {
    return !!user?.counsellor
})
const computedDataHasUser = computed(() => {
    return props.type == 'Therapy' && reportData.value.data?.userId == props.item.user.id && !reportData.value.data?.counsellorId
})
const computedDataHasCounsellor = computed(() => {
    return props.type == 'Therapy' && reportData.value.data?.counsellorId == props.item.counsellor?.id &&
        props.item.counsellor?.id && !reportData.value.data?.userId
})
const computedDataHasBoth = computed(() => {
    return props.type == 'Therapy' && reportData.value.data?.userId == props.item.user.id &&
        props.type == 'Therapy' && reportData.value.data?.counsellorId == props.item.counsellor?.id && props.item.counsellor?.id
})

async function createReport() {
    if (!reportData.value.description) {
        setFailedAlertData({
            message: "Description is required for a report.",
            time: 5000,
        });
        return
    }
    
    if (
        !reportData.value.addedbyType || !reportData.value.addedbyId
    ) {
        reportData.value.addedbyId = user.id
        reportData.value.addedbyId = 'User'
    }
    
    if (
        !reportData.value.reportableType || !reportData.value.reportableId
    ) {
        reportData.value.reportableId = props.item.id
        reportData.value.reportableId = props.type
    }
    
    if (
        props.type == 'Therapy' &&
        !reportData.value.data?.userId && 
        !reportData.value.data?.counsellorId
    )
        reportData.value.data = {'userId': props.item.user.id,}

    clearErrorData(reportErrors, [
        'description', 'data', 'files',
        'reportableType', 'reportableId', 'addedbyType', 'addedbyId',
    ])

    loading.value = true
    await axios.post(route(`api.reports.create`), {...unref(reportData)}, {
            headers: {'Content-Type': 'multipart/form-data'},
        })
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'Your report has been successfully made.',
                time: 4000
            })

            if (res.data.report)
                emits('onSuccess', res.data.report)

            closeModal()
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

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 5000
            })
        
        })

    loading.value = false
}

function changeFile(e) {
    if (!e.target.files?.length) return

    reportData.value.files = [...e.target.files, ...(reportData.value.files ?? [])]
    filesInput.value.value = ''
}

function clickedImages() {
    if (filesInput.value)
        filesInput.value.click()
}

function clearData() {
    reportData.value.description = ''
    reportData.value.reportableId = ''
    reportData.value.reportableType = ''
    reportData.value.addedbyId = user ? user.id : ''
    reportData.value.addedbyType = 'User'
    reportData.value.files = ''
    reportData.value.data = ''
}

function removeUploadFile(idx) {
    reportData.value.files.splice(idx, 1)
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
}

async function getSessions() {
    if (!sessions.value.page) return

    setGetting('sessions')

    await axios
        .get(route('api.sessions.get', {
            therapyId: props.item?.id,
            page: sessions.value.page
        }))
        .then((res) => {
            console.log(res)
            if (sessions.value.page == 1)
                sessions.value.data = []

            sessions.value.data = [...sessions.value.data, ...res.data.data]
            
            updatePage(res)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
        .finally(() => {
            clearGetting()
        })
}

function updatePage(res) {
    if (res.data.links.next) sessions.value.page += 1
    else sessions.value.page = 0
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
                >Make a Report</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`making report`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="createReport"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="mb-4 text-sm text-gray-600">
                            <div class="p-4 rounded bg-gray-200 shadow-sm">
                                Reporting as {{ reportData.addedbyType }}
                            </div>
                            <div v-if="computedIsCounsellor" class="p-4 rounded bg-gray-200 shadow-sm mt-4">
                                <div class="text-left text-sm font-bold mb-2">You may report as</div>
                                <div
                                    class="bg-white rounded p-2 mx-auto w-[80%] cursor-pointer my-2"
                                    v-if="reportData.addedbyType !== 'User'"
                                    @dblclick="() => {
                                        reportData.addedbyType = 'User'
                                        reportData.addedbyId = user.id
                                    }"
                                >
                                    Report as User
                                </div>
                                <div
                                    class="bg-white rounded p-2 mx-auto w-[80%] cursor-pointer my-2"
                                    v-if="reportData.addedbyType !== 'Counsellor'"
                                    @dblclick="() => {
                                        reportData.addedbyType = 'Counsellor'
                                        reportData.addedbyId = user.counsellor?.id
                                    }"
                                >
                                    Report as Counsellor
                                </div>
                            </div>

                            <InputError class="mt-2" :message="reportErrors.addedbyId ?? reportErrors.addedbyType" />
                        </div>
                        
                        <div class="p-4 mb-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-left text-sm font-bold mb-2">What are you reporting</div>
                            <template v-if="type == 'Therapy'">
                                <div class="mt-4 mx-auto max-w-[400px]">
                                    <InputLabel for="type" value="Type" />

                                    <Select
                                        id="type"
                                        class="mt-1 block w-full"
                                        v-model="reportableType"
                                        autocomplete="type"
                                        :options="['THERAPY', 'SESSION']"
                                        :default-option="'select type'"
                                        required
                                    />

                                    <div 
                                        class="rounded-lg bg-stone-100 mt-2 w-full min-h-[100px] p-2 space-x-3 flex justify-start items-center overflow-hidden overflow-x-auto mb-2 transition duration-200"
                                        v-if="reportableType == 'SESSION'"
                                    >
                                        <div v-if="getting.show && getting.type == 'sessions'" class="text-sm text-green-700">getting sessions...</div>
                                        <template v-if="sessions.data.length">
                                            <SessionBadge
                                                v-for="(item, idx) in sessions.data"
                                                :key="idx"
                                                :session="item"
                                                :therapy="item"
                                                :listen="false"
                                                :is-active="item.id == reportData.reportableId && reportData.reportableType == 'Session'"
                                                class="w-[60%] shrink-0"
                                                @dblclick="() => {
                                                    reportData.reportableId = item.id
                                                    reportData.reportableType = 'Session'
                                                }"
                                            />
                                        </template>
                                        <div v-if="sessions.data?.length && sessions.page && !getting.show && getting.type !== 'sessions'">
                                            <div
                                                title="get more"
                                                @click="getSessions"
                                                class="text-gray-600 text-lg cursor-pointer p-2 align-middle hover:border-gray-600 border rounded-sm border-transparent w-fit h-[30px] flex justify-center items-center">
                                                <div>...</div>
                                            </div>
                                        </div>
                                        <div v-if="!sessions.data?.length && !sessions.page" class="w-full shrink">
                                            <div
                                                class="text-gray-600 text-sm p-2 align-middle w-full text-center">
                                                <div>no sessions</div>
                                            </div>
                                        </div>
                                    </div>

                                    <InputError class="mt-2" :message="reportErrors.reportableId ?? reportErrors.reportableType" />
                                </div>
                            </template>
                        </div>
                        
                        <div class="p-4 mb-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-left text-sm font-bold mb-2">Report details</div>
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="description" value="Description" />

                                <TextBox
                                    id="description"
                                    class="mt-1 block w-full"
                                    v-model="reportData.description"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives administrators an idea about what to look into.</div>
                                <InputError class="mt-2" :message="reportErrors.description" />
                            </div>
                        </div>
                        
                        <div class="p-4 mb-4 rounded bg-gray-200 shadow-sm" 
                            v-if="type == 'Therapy' && item.counsellor"
                        >
                            <div class="text-left text-sm font-bold mb-2">Who are you reporting</div>
                            <div class="flex px-3 py-2 justify-start space-x-3 items-center overflow-hidden overflow-x-auto" v-if="type == 'Therapy'">
                                <div
                                    title="double click to select"
                                    class="rounded p-2 mx-auto w-[80%] cursor-pointer my-2 min-w-[150px] text-sm"
                                    :class="[computedDataHasBoth ? 'bg-green-300 text-green-800' : 'bg-white text-gray-600']"
                                    @dblclick="() => {
                                        reportData.data = {
                                            'counsellorId': item.counsellor.id,
                                            'userId': item.user.id,
                                        }
                                    }"
                                >
                                    Report User and Counsellor
                                </div>
                                <div
                                    title="double click to select"
                                    class="rounded p-2 mx-auto w-[80%] cursor-pointer my-2 min-w-[150px] text-sm"
                                    :class="[computedDataHasUser ? 'bg-green-300 text-green-800' : 'bg-white text-gray-600']"
                                    @dblclick="() => {
                                        reportData.data = {'userId': item.user.id}
                                    }"
                                >
                                    Report {{ item.user.fullName }} (User)
                                </div>
                                <div
                                    title="double click to select"
                                    class="rounded p-2 mx-auto w-[80%] cursor-pointer my-2 min-w-[150px] text-sm"
                                    :class="[computedDataHasCounsellor ? 'bg-green-300 text-green-800' : 'bg-white text-gray-600']"
                                    @dblclick="() => {
                                        reportData.data = {'counsellorId': item.counsellor.id}
                                    }"
                                >
                                    Report {{ item.counsellor.name }} (Counsellor)
                                </div>

                                <InputError class="mt-2" :message="reportErrors.data" />
                            </div>
                        </div>

                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-left text-sm font-bold mb-2">Attach Images</div>
                            <div class="w-full max-h-[100px] p-2 flex justify-start overflow-hidden overflow-x-auto items-center space-x-3">
                                <PrimaryButton @click.prevent="clickedImages" class="shrink-0">
                                    get images
                                </PrimaryButton>
                                <PrimaryButton v-if="reportData.files?.length > 1" @click.prevent="() => reportData.files = []" class="shrink-0">
                                    remove images
                                </PrimaryButton>
                                <FilePreview
                                    v-for="(file, idx) in (reportData.files ?? [])"
                                    :key="idx"
                                    :file="file"
                                    :show-remove="true"
                                    class="h-[90px] w-[90px] shrink-0"
                                    @remove-file="() => removeUploadFile(file, idx)"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                            make
                        </PrimaryButton>
                    </div>
                </form>
            </div>
            
            <input 
                type="file"
                ref="filesInput"
                @change="changeFile"
                class="hidden" id="reportFiles" multiple accept="image/*">
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