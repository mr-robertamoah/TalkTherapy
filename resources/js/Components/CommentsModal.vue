<script setup>
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import useAuth from '@/Composables/useAuth';
import CommentBadge from './CommentBadge.vue';
import TextBox from './TextBox.vue';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import PrimaryButton from './PrimaryButton.vue';

const { goToLogin } = useAuth()
const { alertData, clearAlertData, setFailedAlertData } = useAlert()

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    commentable: {
        default: null
    },
    commentableType: {
        default: '',
        type: String
    }
})

const emits = defineEmits(['close', 'created', 'deleted'])

const loading = ref(false)
const commenting = ref(false)
const content = ref('')
const comments = ref({ data: [], page: 1 })

watch(() => props.show, () => {
    if (!props.show) return

    getComments()
})

function deletedComment(comment, idx) {
    comments.value.data.splice(idx, 1)
    emits('deleted', comment)
}

function closeModal() {
    emits('close')
}

async function getComments() {
    if (!comments.value.page) return

    loading.value = true

    await axios
    .get(route('api.comments', {
        commentableType: props.commentableType,
        commentableId: props.commentable.id,
        page: comments.value.page
    }))
    .then((res) => {
        if (comments.value.page == 1)
            comments.value.data = []
        
        comments.value.data = [
            ...comments.value.data,
            ...res.data.data,
        ]

        updatePage(res)
    })
    .catch((err) => {
        goToLogin(err)
    })
    .finally(() => {
        loading.value = false
    })
}

async function createComment() {
    if (!content.value) return

    commenting.value = true

    await axios
    .post(route(`api.comments.create`), {
        commentableType: props.commentableType,
        commentableId: props.commentable.id,
        content: content.value
    })
    .then((res) => {
        
        comments.value.data = [
            res.data.comment,
            ...comments.value.data
        ]

        content.value = ''
        emits('created', res.data.comment)
    })
    .catch((err) => {
        goToLogin(err)

        setFailedAlertData({
            message: "Commenting failed. Something unfortunate happened. Please try again shortly.",
            time: 4000
        })
    })
    .finally(() => {
        commenting.value = false
    })
}

function updatePage(res) {
    if (res.data.links.next) comments.value.page = comments.value.page + 1
    else comments.value.page = 0
}

</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">
            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Comments</div>
                <hr>
            </div>

            <div class="p-2">
                <div class="h-[50vh] overflow-hidden overflow-y-auto p-2 flex justify-center items-center flex-col">
                    <div v-if="loading" class="p-2 text-center lowercase my-2 text-green-300 transition duration-100 rounded mx-auto w-[90%] bg-green-700">getting comments...</div>
                    <div v-if="comments.data?.length" class="h-full w-full flex flex-col space-y-4 items-center">
                        <CommentBadge
                            class="mb-2 w-full"
                            v-for="(comment, idx) in comments.data"
                            :key="comment.id"
                            :comment="comment"
                            @deleted="() => deletedComment(idx)"
                        />
                    </div>
                    <div v-else-if="!loading" class="flex justify-center items-center h-full text-gray-600 text-sm">
                        <div class="lowercase">no comments for this {{ commentableType }}</div>
                    </div>

                    <div v-if="comments.data.length && comments.page && !loading" @click="getComments" title="get more comments" class="mt-6 mb-4 p-4 flex justify-center items-center h-full text-gray-600 text-sm cursor-pointer">
                        <div class="text-gray-600 text-lg cursor-pointer p-2">...</div>
                    </div>
                </div>

                <div v-if="$page.props.auth.user">
                    <div class="text-center text-sm text-gray-600 my-2" v-if="commenting">commenting...</div>
                    <div class="w-[90%] mx-auto">
                        <TextBox
                            id="name"
                            type="text"
                            rows="3"
                            class="mt-1 block w-full"
                            v-model="content"
                            placeholder="add your comment"
                        />

                        <div class="flex justify-end items-center my-2" v-if="content">
                            <PrimaryButton :disabled="commenting" @click="createComment">add comment</PrimaryButton>
                        </div>
                    </div>
                </div>
                <div v-else class="text-xs text-gray-600 text-center">log in to add a comment</div>
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