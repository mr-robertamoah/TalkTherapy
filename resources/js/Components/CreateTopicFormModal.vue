<script setup>
import { computed, onBeforeMount, ref, watch } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import SessionSection from "./SessionSection.vue";
import useErrorHandler from '@/Composables/useErrorHandler';

const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()
const { clearErrorData, setErrorData } = useErrorHandler()

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    therapy: {
        default: null,
    },
    loadedSessions: {
        default: []
    },
    loadedSessionsPage: {
        default: 0
    },
})

const emits = defineEmits(['close', 'onSuccess'])

const loading = ref(false)
const topicData = ref({
    'name': '',
    'description': '',
    'sessions': []
})
const topicErrors = ref({
    'name': '',
    'description': '',
    'sessions': ''
})

async function createTopic() {
    if (!topicData.value.name || !topicData.value.description) {
        setAlertData({
            message: "Name and description are required for a topic.",
            time: 5000,
            show: true,
            type: 'failed'
        });
        return
    }

    loading.value = true

    await axios.post(route(`api.topics.create`, { therapyId: props.therapy.id }), {...topicData.value})
        .then((res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your topic has been successfully created.',
                type: 'success',
                show: true,
                time: 4000
            })
            emits('onSuccess', res.data.topic)
            closeModal()
        })
        .catch((err) => {
            console.log(err)
            if (err.response?.status == 422) {
                setErrorData(topicErrors, err.response.data.errors, ['name', 'description', 'sessions'])
                setFailedAlertData({
                    message: 'There has been a validation error. Please check your form.',
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
        .finally(() => {
            loading.value = false
        })
}

function clearData() {
    topicData.value.name = ''
    topicData.value.description = ''
    topicData.value.sessions = []
}

function closeModal() {
    clearData()
    clearErrorData(topicErrors, ['name', 'description', 'sessions'])
    emits('close')
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
                >Create Topic</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`creating topic`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="createTopic"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="name" value="Name" />

                                <TextInput
                                    id="name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="topicData.name"
                                    required
                                    autofocus
                                />
                                
                                <InputError class="mt-2" :message="topicErrors.name" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="description" value="Description" />

                                <TextBox
                                    id="description"
                                    class="mt-1 block w-full"
                                    v-model="topicData.description"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives the user an idea about what this topic will be.</div>
                                <InputError class="mt-2" :message="topicErrors.description" />
                            </div>
                        </div>

                        <div class="p-4 rounded bg-gray-200 shadow-sm my-4">
                            <SessionSection
                                :loaded-sessions="loadedSessions"
                                :loaded-sessions-page="loadedSessionsPage"
                                :therapy="therapy"
                                @on-data="(data) => {
                                    if (data) topicData.sessions = [...data.map((d) => d.id)]
                                }"
                            />

                            <InputError class="mt-2" :message="topicErrors.sessions" />
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