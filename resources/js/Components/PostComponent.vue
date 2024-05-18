<template>
    <div v-bind="$attrs" class="relative bg-stone-200 rounded w-80 p-2">
        <FormLoader :show="loading" :text="post?.id ? 'updating' : 'creating' "/>
        <div class="flex space-x-2 justify-end items-center w-full p-2" v-if="computedIsAddedby">
            <PrimaryButton @click="() => showModal('update')" class="shrink-0">update</PrimaryButton>
            <DangerButton @click="() => showModal('delete')" class="shrink-0">delete</DangerButton>
        </div>
        <div v-if="computedIsAddedby" class="my-1 h-1 bg-white"></div>
        <div class="text-xs text-gray-600 font-bold text-end mt-2" v-if="post?.id">
            <div v-if="post?.fromAdmin">Posted by Admin</div>
            <div v-else>Posted by Counsellor</div>
        </div>

        <div v-if="post?.counsellor" class="flex justify-start items-center my-3 cursor-pointer space-x-2 p-2 bg-stone-100 rounded">
            <Avatar class="shrink-0" :avatar-text="'...'" :size="40" :src="post?.counsellor?.avatar ?? ''"/>
            <div class="text-gray-600 flex justify-start items-center shrink space-x-2 text-xs">
                <div class="capitalize">{{ post?.counsellor.name }}</div>
                <div>{{ post?.counsellor.username ? `(${post?.counsellor.username})` : '' }}</div>
            </div>
        </div>
        <div 
            v-if="post?.content" 
            class="text-sm text-gray-600 text-pretty my-2 w-[90%] mx-auto"
        >
            <span>{{ computedContent }}</span>
            <span
                @click="() => showMore = !showMore"
                v-if="post?.content?.length > 100"
                class="ml-2 cursor-pointer text-xs my-1 text-blue-600 underline">show {{ showMore ? 'less' : 'more' }}</span>
        </div>
        <div v-if="post.files?.length" class="w-[90%] mx-auto my-2">
            <div class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 space-x-2">
                <FilePreview
                    v-for="(file, idx) in post.files"
                    :key="idx"
                    :file="file"
                    class="w-[200px] cursor-pointer shrink-0"
                    :show-remove="false"
                    
                    @click="() => {
                        if (!id) return
                        showModal('file')
                        currentFileIdx = idx
                    }"
                />
            </div>
            <div v-if="post.files[0].id" class="text-xs text-gray-400 text-center my-2">click image to view</div>
        </div>
        <div class="flex justify-end items-center space-x-2">
            <div 
                class="text-xs text-end my-1 lowercase"
                :class="[['sending', 'retrying', 'updating'].includes(status) ? 'text-green-700' : (failed ? 'text-red-700 cursor-pointer' : 'text-gray-600')]"
                v-if="failed"
                @click="() => {
                    if (!msg.id) createMessage()
                    if (msg.id) updateMessage()
                }"
            >failed</div>
            <div v-if="post?.createdAt" class="text-xs text-gray-600">{{ toDiffForHumans(post.createdAt) }}</div>
        </div>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />

    <UpdatePostModal
        :show="modalData.show && modalData.type == 'update'"
        :post="post"
        @updated="updatePost"
        @close="() => closeModal()"
    />

    <FileModal
        v-if="post.files?.length"
        :has-download="!!id"
        :file="post.files[currentFileIdx]"
        :show="modalData.show && modalData.type == 'file'"
        :next="currentFileIdx + 1 !== post.files.length"
        :previous="currentFileIdx - 1 !== -1"
        @closeModal="() => closeModal()"

        @clicked-next="() => {
            if (currentFileIdx + 1 == post.files.length) return
            currentFileIdx += 1
        }"
        @clicked-previous="() => {
            if (currentFileIdx - 1 == -1) return
            currentFileIdx -= 1
        }"
    />

    <MiniModal
        :show="modalData.show && ['delete'].includes(modalData.type)"
        @close="closeModal"
    >
        <div v-if="modalData.type == 'delete'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete Post
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                    Are you sure you want to delete this post?
                </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                <DangerButton @click="deletePost" class="shrink-0">delete</DangerButton>
            </div>
        </div>

    </MiniModal>
</template>

<script setup>
import useAlert from '@/Composables/useAlert';
import useModal from '@/Composables/useModal';
import { ref, watchEffect } from 'vue';
import Alert from './Alert.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import UpdatePostModal from './UpdatePostModal.vue';
import MiniModal from './MiniModal.vue';
import FileModal from './FileModal.vue';
import FilePreview from './FilePreview.vue';
import FormLoader from './FormLoader.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Avatar from './Avatar.vue';
import useLocalDateTimed from '@/Composables/useLocalDateTime';


const { alertData, clearAlertData, setFailedAlertData } = useAlert()
const { modalData, closeModal, showModal } = useModal()
const { toDiffForHumans } = useLocalDateTimed()

const emits = defineEmits(['updated', 'deleted', 'created'])

const props = defineProps({
    post: {
        default: null
    },
})

const loading = ref(false)
const showMore = ref(false)
const failed = ref(false)
const status = ref('')
const id = ref(null)
const currentFileIdx = ref(0)

watchEffect(() => {
    if ((props.post?.files || props.post?.content) && status.value == 'sending' && !props.post?.id && !loading.value)
        createPost()
    
    if ((props.post?.files || props.post?.content) && status.value == 'updating' && props.post?.id && !loading.value)
        updatePost()
})
watchEffect(() => {
    if (props.post?.status)
        status.value = props.post.status
})
watchEffect(() => {
    if (props.post?.id)
        id.value = props.post.id
})

const computedIsAddedby = computed(() => {
    const user = usePage().props.auth.user

    if (props.post?.fromAdmin && user.isAdmin) return true
    
    if (!props.post?.counsellor) return false

    return (props.post?.counsellor.userId == user.id) ? true : false
})
const computedContent = computed(() => {
    if (!props.post?.content) return ''

    if (showMore.value) return props.post?.content
    
    return props.post?.content?.length > 100 ? props.post?.content.slice(0, 100) + '...' : props.post?.content
})

async function createPost() {
    if (loading.value) return
    
    status.value = 'sent'
    failed.value = false
    loading.value = true

    let data = {
            ...props.post,
        }
    
    await axios
        .post(route('api.posts.create'), data, {
            headers: {'Content-Type': 'multipart/form-data'},
        })
        .then((res) => {
            console.log(res)

            emits('created', res.data.post)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            
            failed.value = true
            
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 4000,
                })
                return
            }

            setFailedAlertData({
                message: "Post was not sent. Clicked 'failed' to retry."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

async function updatePost(post) {
    if (loading.value) return
    
    loading.value = true
    failed.value = false
    status.value = ''
    
    await axios
        .post(route('api.posts.update', { postId: post.id }), {
            content: post.content,
            files: post.files ? post.files.filter((file) => file.id ? false : true) : [],
            deletedFiles: post.deletedFiles ? post.deletedFiles.map((file) => file.id) : [],
        }, {
            headers: {'Content-Type': 'multipart/form-data'},
        })
        .then((res) => {
            console.log(res)

            emits('updated', res.data.post)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            
            failed.value = true
            setFailedAlertData({
                message: "Post was not updated. Clicked 'failed' to retry."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

async function deletePost() {
    closeModal()
    if (loading.value) return
    
    loading.value = true
    
    await axios
        .delete(route('api.posts.delete', { postId: props.post.id }))
        .then((res) => {
            console.log(res)

            emits('deleted', res.data.post)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            
            setFailedAlertData({
                message: "Post was not deleted. Try again in a short while."
            })
        })
        .finally(() => {
            loading.value = false
        })
}

</script>