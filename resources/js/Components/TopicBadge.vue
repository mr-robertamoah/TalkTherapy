<template>
    <div v-bind="$attrs" class="rounded bg-white shadow-sm p-2 select-none cursor-pointer">
        <div class="text-xs my-2 w-fit ml-auto mr-2 text-gray-600">{{ topic.createdAt }}</div>
        <div class="capitalize text-gray-600 text-sm sm:text-base font-bold tracking-wide px-2">
            {{ topic.name }}
        </div>
        <div class="text-gray-600 text-sm my-2 p-2 px-4 text-center" v-if="computedDescription">
            {{ computedDescription }}
        </div>
        <div class="flex justify-end items-center my-2">
            <div 
                v-if="computedCanPerformActions"
                @click="() => showModal('actions')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >show actions</div>
            <div 
                @click="() => showModal('details')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >show details</div>
        </div>
    </div>

    <TopicModal
        :topic="topic"
        :show="modalData.type == 'details' && modalData.show"
        @close="closeModal"
        v-if="topic"
    />
    <UpdateTopicFormModal
        :topic="topic"
        :therapy="therapy"
        :loaded-sessions="loadedSessions"
        :loaded-sessions-page="loadedSessionsPage"
        :show="modalData.type == 'update' && modalData.show"
        @close="closeModal"
        @on-update="(data) => {
            if (data) emits('onUpdate', data)
        }"
        v-if="topic"
    />
    <MiniModal
        :show="['actions', 'delete'].includes(modalData.type) && modalData.show"
        @close="closeModal"
        v-if="topic"
    >
        <div v-if="modalData.type == 'actions'">
            <div class="text-gray-600 text-center font-bold tracking-wide">Actions</div>
            <hr class="my-2">

            <div class="p-4 flex flex-col items-center justify-center mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton class="mb-2 text-center" @click="() => {
                    closeModal()
                    showModal('update')
                }">update</PrimaryButton>
                <PrimaryButton class="mb-2 text-center" @click="() => {
                    closeModal()
                    showModal('delete')
                }">delete</PrimaryButton>
            </div>
        </div>
        <div v-if="modalData.type == 'delete'" class="relative">
            <div class="text-red-700 text-center font-bold tracking-wide">Delete Topic</div>
            <hr class="my-2">
            <FormLoader :danger="true" :show="loading" :text="'deleting topic'"/>
            <div class="text-red-700 my-4 w-[90%] mx-auto text-center">Are sure you want to delete this topic.</div>
            <div class="flex p-4 items-center justify-end mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton @click="closeModal">cancel</PrimaryButton>
                <DangerButton class="ml-2" @click="deleteSession">delete</DangerButton>
            </div>
        </div>
    </MiniModal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import useModal from '@/Composables/useModal';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import TopicModal from './TopicModal.vue';
import MiniModal from './MiniModal.vue';
import PrimaryButton from './PrimaryButton.vue';
import UpdateTopicFormModal from './UpdateTopicFormModal.vue';
import FormLoader from './FormLoader.vue';
import DangerButton from './DangerButton.vue';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';

const { modalData, closeModal, showModal } = useModal()
const { alertData, setAlertData, setFailedAlertData, clearAlertData } = useAlert()

const emits = defineEmits(['onUpdate', 'onDelete'])

const props = defineProps({
    topic: {
        default: null
    },
    therapy: {
        default: null
    },
    loadedSessions: {
        default: []
    },
    loadedSessionsPage: {
        default: 0
    },
})

const mainSession = ref(null)
const loading = ref(false)

watch(() => props.topic?.id, () => {
    if (props.topic?.id) mainSession.value = {...props.topic}
})

const computedDescription = computed(() => {
    return props.topic?.description?.length > 100 ? props.topic?.description?.slice(0, 100) + '...' : props.topic?.description
})
const computedCanPerformActions = computed(() => {
    return props.topic?.userId == usePage().props.auth.user?.id
})

async function deleteSession() {
    loading.value = true    

    await axios.delete(route(`api.topics.delete`, { topicId: props.topic.id }))
        .then((res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your topic has been successfully deleted.',
                type: 'success',
                show: true,
                time: 4000
            })
            emits('onDelete', res.data.topic)
            closeModal()
        })
        .catch((err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                    time: 5000,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 4000
            })
        })
        .finally(() => {
            loading.value = false
        })
}
</script>