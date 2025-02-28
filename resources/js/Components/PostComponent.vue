<template>
    <div v-bind="$attrs" class="relative">
        <div class="relative bg-stone-200 rounded p-2">
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
                <span>{{ getShowMoreContent(post.content) }}</span>
                <span
                    @click="toggleShowMore"
                    v-if="post?.content?.length > 100"
                    class="ml-2 cursor-pointer text-xs my-1 text-blue-600 underline">show {{ showMore ? 'less' : 'more' }}</span>
            </div>
            <div v-if="post.files?.length" class="w-[90%] mx-auto my-2">
                <div 
                    class="flex items-center overflow-hidden overflow-x-auto p-2 space-x-2"
                    :class="[post.files?.length == 1 ? 'justify-center' : 'justify-start']"
                >
                    <FilePreview
                        v-for="(file, idx) in post.files"
                        :key="idx"
                        :file="file"
                        class="w-[280px] sm:w-[300px] cursor-pointer shrink-0"
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
                <div v-if="post?.createdAt" class="text-xs text-gray-600">{{ post.createdAt }}</div>
            </div>
        </div>
        <div class="w-full h-2 bg-white"></div>
        <div class="relative bg-stone-200 rounded p-2 flex justify-between text-xs items-center space-x-2 select-none">
            <div class="flex flex-col justify-center p-2 items-center space-x-2"
                @click="() => {
                    if (computedHasLiked) {
                        clickedDislike()
                        return
                    }
                    clickedLike()
                }"
            >
                <div class="flex justify-start items-center space-x-4">
                    <DarkLikeIcon
                        title="unlike post" v-if="computedHasLiked" class="cursor-pointer w-5 h-5 text-green-600"/>
                    <LikeIcon
                        :title="$page.props.auth.user ? 'like post' : ''" v-else class="cursor-pointer w-5 h-5 text-green-600"/>
                    <div class="text-sm font-bold">{{ post.likes?.length }}</div>
                </div>
                <div class="mt-2 text-stone-400 font-bold">likes</div>
            </div>
            <div class="flex flex-col justify-center p-2 items-center space-x-2"
                @click="() => showModal('comments')"
            >
                <div class="flex justify-start items-center space-x-4">
                    <CommentIcon
                        title="show comments"
                        class="cursor-pointer w-5 h-5 text-green-600"/>
                    <div class="text-sm font-bold">{{ post.comments }}</div>
                </div>
                <div class="mt-2 text-stone-400 font-bold">comments</div>
            </div>
        </div>
        <div v-if="showShare" class="flex justify-end mb-2">
            <div class="p-2" @click="() => showModal('share')">
                <ShareIcon
                    title="share post"
                    class="cursor-pointer w-5 h-5 text-green-600"
                />
            </div>
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

    <CommentsModal
        :show="modalData.show && modalData.type == 'comments'"
        :commentable="post"
        :commentableType="'Post'"
        @close="() => closeModal()"
        @deleted="commentDeleted"
        @created="commentCreated"
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
        :show="modalData.show && ['delete', 'share'].includes(modalData.type)"
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
        <div v-if="modalData.type == 'share'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Copy Link
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="flex justify-end my-2">
                    <CopyIcon
                        @click="copyLink"
                        title="copy"
                        class="cursor-pointer w-5 h-5 text-green-600"/>
                </div>
                <div class="text-sm text-center w-[90%] mx-auto text-blue-600">talktherapy.tech/posts/{{ post.id }}</div>
                <div v-if="copying" class="text-xs text-gray-600 my-2 text-end w-full">{{ copying }}</div>
            </div>
        </div>
    </MiniModal>
</template>

<script setup>
import useAlert from '@/Composables/useAlert';
import useModal from '@/Composables/useModal';
import useShowMore from '@/Composables/useShowMore';
import LikeIcon from '@/Icons/LikeIcon.vue';
import DarkLikeIcon from '@/Icons/DarkLikeIcon.vue';
import CommentIcon from '@/Icons/CommentIcon.vue';
import { ref, watchEffect } from 'vue';
import Alert from './Alert.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import UpdatePostModal from './UpdatePostModal.vue';
import MiniModal from './MiniModal.vue';
import FileModal from './FileModal.vue';
import FilePreview from './FilePreview.vue';
import FormLoader from './FormLoader.vue';
import ShareIcon from '@/Icons/ShareIcon.vue';
import CopyIcon from '@/Icons/CopyIcon.vue';
import CommentsModal from './CommentsModal.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Avatar from './Avatar.vue';


const { alertData, clearAlertData, setFailedAlertData } = useAlert()
const { showMore, toggleShowMore, getShowMoreContent } = useShowMore()
const { modalData, closeModal, showModal } = useModal()

const emits = defineEmits(['updated', 'deleted', 'created'])

const props = defineProps({
    post: {
        default: null
    },
    showShare: {
        default: true
    }
})

const loading = ref(false)
const copying = ref('')
const liking = ref(false)
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

const computedHasLiked = computed(() => {
    const user = usePage().props.auth.user

    if (!user || !props.post?.likes?.length) return false

    if (props.post.likes.find((userId) => userId == user.id)) return true

    return false
})

const computedIsAddedby = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    if (props.post?.fromAdmin && user.isAdmin) return true
    
    if (!props.post?.counsellor) return false

    return (props.post?.counsellor.userId == user.id) ? true : false
})

function copyLink() {
    copying.value = 'copying...'
    setTimeout(() => {
        copying.value = 'copied.'
    }, 1000)
    navigator.clipboard.writeText(`www.talktherapy.tech/posts/${props.post.id}`)
    setTimeout(() => {
        copying.value = ''
    }, 2000)
}

function commentCreated(comment) {
    emits('updated', {...props.post, comments: props.post.comments + 1})
}

function commentDeleted(comment) {
    emits('updated', {...props.post, comments: props.post.comments - 1})
}

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

async function clickedDislike() {
    if (liking.value) return

    const user = usePage().props.auth.user
    if (!user) return
    
    liking.value = true

    let likes = [...props.post.likes]
    likes.splice(props.post.likes.indexOf(user.id), 1)
    console.log(likes)
    emits('updated', {...props.post, likes})
    await axios
        .post(route('api.likes.delete'), {
            likeableType: 'Post',
            likeableId: props.post?.id,
        })
        .then((res) => {
            console.log(res)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            
            emits('updated', {...props.post, likes: [user.id, ...likes]})
            
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            setFailedAlertData({
                message: "Unliking failed, please try again shortly.",
            })
        })
        .finally(() => {
            liking.value = false
        })
}

async function clickedLike() {
    if (liking.value) return

    const user = usePage().props.auth.user
    if (!user) return
    
    liking.value = true

    let likes = [user.id, ...props.post.likes]
    emits('updated', {...props.post, likes})
    await axios
        .post(route('api.likes.create'), {
            likeableType: 'Post',
            likeableId: props.post?.id,
        })
        .then((res) => {
            console.log(res)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
            
            likes.splice(likes.indexOf(user.id), 1)
            emits('updated', {...props.post, likes})
            
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            setFailedAlertData({
                message: "Liking failed, please try again shortly.",
            })
        })
        .finally(() => {
            liking.value = false
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