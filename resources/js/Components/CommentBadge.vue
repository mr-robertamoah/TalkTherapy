<template>
    <div v-bind="$attrs" class="">
        <div v-if="loading" class="text-xs text-center text-red-600">deleting...</div>
        <div class="text-gray-600 font-bold text-xs">@{{ comment.username }}</div>
        <div class="ml-4 text-sm text-gray-600 bg-stone-200 p-2 rounded">{{ comment.content }}</div>
        <div class="flex justify-between items-center">
            <div class="text-xs text-end text-red-600 mb-2 cursor-pointer" v-if="computedCommented" @click="() => showModal('delete')">delete</div>
            <div 
                class="text-xs text-gray-400 my-2 text-end"
                :class="{'w-fit ml-auto': !computedCommented}"
            >{{ comment.createdAt }}</div>
        </div>
    </div>

    <MiniModal
        :show="modalData.show && 'delete' == modalData.type"
        @close="closeModal"
    >
        <div v-if="modalData.type == 'delete'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete Comment
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                    Are you sure you want to delete this comment?
                </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                <DangerButton @click="deleteComment" class="shrink-0">delete</DangerButton>
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
import MiniModal from './MiniModal.vue';
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from './PrimaryButton.vue';
import DangerButton from './DangerButton.vue';
import useAuth from '@/Composables/useAuth';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';


const { modalData, closeModal, showModal } = useModal()
const { goToLogin } = useAuth()
const { alertData, clearAlertData, setFailedAlertData } = useAlert()

const emits = defineEmits(['deleted'])

const props = defineProps({
    comment: {
        default: null
    }
})

const loading = ref(false)

const computedCommented = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    return (user.id == props.comment?.userId) ? true : false
})

async function deleteComment() {
    closeModal()
    if (loading.value) return
    
    loading.value = true
    
    await axios
        .delete(route('api.comments.delete', { commentId: props.comment.id }))
        .then((res) => {

            emits('deleted', res.data.comment)
        })
        .catch((err) => {
            goToLogin(err)
            
            setFailedAlertData({
                message: "Comment was not deleted. Try again in a short while."
            })
        })
        .finally(() => {
            loading.value = false
        })
}
</script>