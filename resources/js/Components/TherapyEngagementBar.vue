<script setup>
// SCRUM-354: likes and comments on a public therapy or group. Renders only when the resource
// includes `engagement`, which the server sends for public therapies alone. Likes are a count and
// the viewer's own state -- the server never says who liked a therapy.
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import LikeIcon from '@/Icons/LikeIcon.vue'
import DarkLikeIcon from '@/Icons/DarkLikeIcon.vue'
import CommentIcon from '@/Icons/CommentIcon.vue'
import CommentsModal from './CommentsModal.vue'
import Alert from './Alert.vue'
import useAlert from '@/Composables/useAlert'
import useAuth from '@/Composables/useAuth'

const props = defineProps({
  therapy: { type: Object, required: true },
  // 'Therapy' or 'GroupTherapy' -- the likeable/commentable type the API expects.
  type: { type: String, required: true },
})

const { goToLogin } = useAuth()
const { alertData, clearAlertData, setFailedAlertData } = useAlert()

const likesCount = ref(0)
const isLiked = ref(false)
const commentsCount = ref(0)
const liking = ref(false)
const showComments = ref(false)

watch(
  () => props.therapy?.engagement,
  (engagement) => {
    likesCount.value = engagement?.likesCount ?? 0
    isLiked.value = !!engagement?.isLiked
    commentsCount.value = engagement?.commentsCount ?? 0
  },
  { immediate: true },
)

const canLike = computed(() => !!usePage().props.auth?.user)

async function toggleLike() {
  if (liking.value || !canLike.value) return

  liking.value = true
  const wasLiked = isLiked.value
  // Optimistic, rolled back on failure.
  isLiked.value = !wasLiked
  likesCount.value += wasLiked ? -1 : 1

  await axios
    .post(route(wasLiked ? 'api.likes.delete' : 'api.likes.create'), {
      likeableType: props.type,
      likeableId: props.therapy.id,
    })
    .catch((err) => {
      goToLogin(err)
      isLiked.value = wasLiked
      likesCount.value += wasLiked ? 1 : -1
      setFailedAlertData({
        message: err.response?.data?.message ?? 'Something went wrong, please try again shortly.',
      })
    })
    .finally(() => {
      liking.value = false
    })
}
</script>

<template>
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
    <div class="bg-white border border-gray-200 shadow-sm sm:rounded-lg px-4 py-2 flex items-center space-x-2">
      <button
        type="button"
        class="flex items-center space-x-2 px-3 py-2 rounded-lg transition-colors disabled:cursor-default"
        :class="canLike ? 'hover:bg-gray-50' : ''"
        :disabled="!canLike || liking"
        :aria-pressed="isLiked"
        :title="canLike ? (isLiked ? 'unlike' : 'like') : 'log in to like'"
        @click="toggleLike"
      >
        <DarkLikeIcon v-if="isLiked" class="w-5 h-5 text-blue-600" />
        <LikeIcon v-else class="w-5 h-5 text-gray-500" />
        <span class="text-sm font-medium text-gray-700">{{ likesCount }}</span>
        <span class="text-sm text-gray-500">{{ likesCount === 1 ? 'like' : 'likes' }}</span>
      </button>
      <button
        type="button"
        class="flex items-center space-x-2 px-3 py-2 rounded-lg hover:bg-gray-50 transition-colors"
        @click="showComments = true"
      >
        <CommentIcon class="w-5 h-5 text-gray-500" />
        <span class="text-sm font-medium text-gray-700">{{ commentsCount }}</span>
        <span class="text-sm text-gray-500">{{ commentsCount === 1 ? 'comment' : 'comments' }}</span>
      </button>
    </div>

    <CommentsModal
      :show="showComments"
      :commentable="therapy"
      :commentable-type="type"
      @close="showComments = false"
      @created="commentsCount += 1"
      @deleted="commentsCount = Math.max(0, commentsCount - 1)"
    />

    <Alert
      :show="alertData.show"
      :type="alertData.type"
      :message="alertData.message"
      :time="alertData.time"
      @close="clearAlertData"
    />
  </div>
</template>
