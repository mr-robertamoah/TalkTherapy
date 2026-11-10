<template>
    <div v-bind="$attrs" class="bg-white rounded-lg p-4 shadow-sm border border-gray-100">
        <div v-if="loading" class="text-xs text-center text-red-600 bg-red-50 p-2 rounded mb-2">Deleting...</div>
        <div class="flex items-start space-x-3">
            <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
                {{ comment.username?.charAt(0)?.toUpperCase() || 'U' }}
            </div>
            <div class="flex-1">
                <div class="flex items-center space-x-2 mb-2">
                    <span class="font-semibold text-gray-800 text-sm">@{{ comment.username }}</span>
                    <span class="text-xs text-gray-500">{{ comment.createdAt }}</span>
                </div>
                <div class="text-sm text-gray-700 leading-relaxed bg-gray-50 p-3 rounded-lg">{{ comment.content }}</div>
                <div class="mt-2 flex justify-end" v-if="computedCommented">
                    <button 
                        @click="() => showModal('delete')"
                        class="text-xs text-red-600 hover:text-red-800 font-medium transition-colors"
                    >Delete</button>
                </div>
            </div>
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