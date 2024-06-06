<template>
    <div v-bind="$attrs" 
        class="rounded shadow-sm p-2 select-none cursor-pointer"
        :class="[isActive ? 'bg-green-300' : 'bg-white']"
    >
        <div class="flex justify-between items-center">
            <div class="text-xs rounded bg-gray-500 text-white p-1">{{ session.status }}</div>
            <div v-if="session.createdAt" class="text-xs my-2 w-fit ml-auto mr-2 text-gray-600">{{ toDiffForHumans(session.createdAt) }}</div>
        </div>
        <div class="capitalize text-gray-600 text-sm sm:text-base font-bold tracking-wide px-2">
            {{ session.name }}
        </div>
        <div class="text-gray-600 text-xs sm:text-sm my-2 p-2 px-4 text-center" v-if="computedAbout">
            {{ computedAbout }}
        </div>
        <div class="flex justify-end items-center my-2">
            <div 
                v-if="computedCanPerformActions"
                @click="() => showModal('actions')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >show actions</div>
            <div 
                v-if="showDetails"
                @click="() => showModal('details')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >show details</div>
        </div>
    </div>

    <SessionModal
        :session="session"
        :show="modalData.type == 'details' && modalData.show"
        @close="closeModal"
        v-if="session"
    />
    <UpdateSessionFormModal
        :session="session"
        :therapy="therapy"
        :loaded-topics="loadedTopics"
        :loaded-topics-page="loadedTopicsPage"
        :show="modalData.type == 'update' && modalData.show"
        @close="closeModal"
        @on-update="(data) => {
            if (data) emits('onUpdate', data)
        }"
        v-if="session && hasActions"
    />
    <MiniModal
        :show="['actions', 'delete'].includes(modalData.type) && modalData.show"
        @close="closeModal"
        v-if="session"
    >
        <div v-if="modalData.type == 'actions'">
            <div class="text-gray-600 text-center font-bold tracking-wide">Actions</div>
            <hr class="my-2">

            <div class="p-4 flex flex-col items-center justify-center mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton class="mb-2 text-center" @click="() => showModal('update')">update</PrimaryButton>
                <PrimaryButton class="mb-2 text-center" @click="() => showModal('delete')">delete</PrimaryButton>
            </div>
        </div>
        <div v-if="modalData.type == 'delete'" class="relative">
            <div class="text-red-700 text-center font-bold tracking-wide">Delete Session</div>
            <hr class="my-2">
            <FormLoader :danger="true" :show="loading" :text="'deleting session'"/>
            <div class="text-red-700 my-4 w-[90%] mx-auto text-center">Are sure you want to delete this session.</div>
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
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import SessionModal from './SessionModal.vue';
import MiniModal from './MiniModal.vue';
import PrimaryButton from './PrimaryButton.vue';
import UpdateSessionFormModal from './UpdateSessionFormModal.vue';
import FormLoader from './FormLoader.vue';
import DangerButton from './DangerButton.vue';
import useAlert from '@/Composables/useAlert';
import useLocalDateTime from '@/Composables/useLocalDateTime';
import Alert from './Alert.vue';

const { modalData, closeModal, showModal } = useModal()
const { toDiffForHumans } = useLocalDateTime()
const { alertData, setAlertData, setFailedAlertData, clearAlertData } = useAlert()

const emits = defineEmits(['onUpdate', 'onDelete', 'onMessageCreated'])

const props = defineProps({
    session: {
        default: null
    },
    therapy: {
        default: null
    },
    isActive: {
        default: false
    },
    hasActions: {
        default: true
    },
    showDetails: {
        default: true
    },
    listen: {
        default: true
    },
    loadedTopics: {
        default: []
    },
    loadedTopicsPage: {
        default: 0
    },
})

const mainSession = ref(null)
const loading = ref(false)

onBeforeUnmount(() => {
    if (props.listen)
        Echo.leave(`sessions.${props.session.id}`)
})

watchEffect(() => {
    if (props.session?.id && props.listen) {
        mainSession.value = {...props.session}

        Echo
            .private(`sessions.${props.session.id}`)
            .listen('.message.created', (data) => {
                if (data.message?.fromUserId == usePage().props.auth.user?.id)
                    return

                emits('onMessageCreated', data.message)
            })
            .listen('.session.updated', (data) => {
                emits('onUpdate', data.session)
            })
    }
})

const computedAbout = computed(() => {
    return props.session?.about?.length > 100 ? props.session?.about?.slice(0, 100) + '...' : props.session?.about
})
const computedCanPerformActions = computed(() => {
    return props.hasActions && props.session?.userId == usePage().props.auth.user?.id
})

async function deleteSession() {
    loading.value = true    

    await axios.delete(route(`api.sessions.delete`, { sessionId: props.session.id }))
        .then((res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your session has been successfully deleted.',
                type: 'success',
                show: true,
                time: 4000
            })
            emits('onDelete', res.data.session)
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