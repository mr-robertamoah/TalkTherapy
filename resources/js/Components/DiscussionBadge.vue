<template>
    <div v-bind="$attrs" class="rounded bg-white shadow-sm p-2 select-none cursor-pointer">
        <div class="text-xs my-2 w-fit ml-auto mr-2 text-gray-600">{{ discussion.createdAt }}</div>
        <div class="capitalize text-gray-600 font-bold tracking-wide px-2">
            {{ discussion.name }}
        </div>
        <div class="text-gray-600 text-sm my-2 p-2 px-4 text-center" v-if="computedDescription">
            <span>{{ computedDescription }}</span>
            <span
                @click="() => showMore = !showMore"
                v-if="discussion?.description?.length > 100"
                class="ml-2 cursor-pointer text-xs my-1 text-blue-600 underline">show {{ showMore ? 'less' : 'more' }}</span>
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

    <DiscussionModal
        :discussion="discussion"
        :show="modalData.type == 'details' && modalData.show"
        @close="closeModal"
        v-if="discussion"
    />
    <UpdateDiscussionFormModal
        :discussion="discussion"
        :show="modalData.type == 'update' && modalData.show"
        @close="closeModal"
        @on-update="(data) => {
            if (data) emits('onUpdate', data)
        }"
        v-if="discussion"
    />
    <MiniModal
        :show="['actions', 'delete'].includes(modalData.type) && modalData.show"
        @close="closeModal"
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
            <div class="text-gray-600 text-center font-bold tracking-wide">Actions</div>
            <hr class="my-2">
            <FormLoader :danger="true" :show="loading" :text="'deleting discussion'"/>
            <div class="text-red-700 my-4 w-[90%] mx-auto text-center">Are sure you want to delete this discussion.</div>
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
import DiscussionModal from './DiscussionModal.vue';
import MiniModal from './MiniModal.vue';
import PrimaryButton from './PrimaryButton.vue';
import UpdateDiscussionFormModal from './UpdateDiscussionFormModal.vue';
import FormLoader from './FormLoader.vue';
import DangerButton from './DangerButton.vue';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';

const { modalData, closeModal, showModal } = useModal()
const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const emits = defineEmits(['onUpdate', 'onDelete'])

const props = defineProps({
    discussion: {
        default: null
    },
    therapy: {
        default: null
    },
    showActions: {
        default: false
    },
    showDetails: {
        default: false
    }
})

const mainSession = ref(null)
const loading = ref(false)
const showMore = ref(false)

watch(() => props.discussion?.id, () => {
    if (props.discussion?.id) mainSession.value = {...props.discussion}
})

const computedDescription = computed(() => {
    if (!props.discussion?.description) return ''

    if (showMore.value) return props.discussion?.description
    
    return props.discussion?.description?.length > 100 ? props.discussion?.description.slice(0, 100) + '...' : props.discussion?.description
})
const computedCanPerformActions = computed(() => {
    return props.showActions && 
        props.discussion?.addedby?.id && 
        props.discussion?.addedby?.id == usePage().props.auth.user?.counsellor?.id
})

async function deleteSession() {
    loading.value = true    

    await axios.delete(route(`api.discussions.delete`, { discussionId: props.discussion.id }))
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'Your discussion has been successfully deleted.',
            })
            emits('onDelete', res.data.discussion)
            closeModal()
        })
        .catch((err) => {
            console.log(err)
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
</script>