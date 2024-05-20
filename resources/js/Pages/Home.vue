<script setup>
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue';
import CreatePostModal from '@/Components/CreatePostModal.vue';
import StarredCounsellorComponent from '@/Components/StarredCounsellorComponent.vue';
import HelpButton from '@/Components/HelpButton.vue';
import PostComponent from '@/Components/PostComponent.vue';
import PostModal from '@/Components/PostModal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeMount, provide, ref, watch, watchEffect } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import useModal from '@/Composables/useModal';


const { modalData, showModal, closeModal } = useModal()

const props = defineProps({
    recentTherapies: {
        default: []
    },
    bestCounsellors: {
        default: []
    },
    leadingCounsellors: {
        default: []
    },
    post: {
        default: null
    }
})

const newTherapy = ref(null)
const getting = ref({ show: false, type: '' })
const recentTherapies = ref([])
const posts = ref({ data: [], page: 1 })
const randomTherapies = ref({ data: [], page: 1 })
const randomCounsellors = ref({ data: [], page: 1 })

watch(() => newTherapy.value, () => {
    if (newTherapy.value)
        recentTherapies.value = [newTherapy.value, ...recentTherapies.value]
})
watch(() => usePage().props.auth.user?.id, () => {
    loadContent()
})
watchEffect(() => {
    if (props.post?.id || props.post?.data?.id)
        showPost()
})

onBeforeMount(() => {
    if (props.recentTherapies?.length)
        recentTherapies.value = [...props.recentTherapies]
    
    if (props.recentTherapies?.data?.length)
        recentTherapies.value = [...props.recentTherapies.data]

    loadContent()
})

provide('onCreatedNewTherapy', { newTherapy, updateNewTherapy })

const computedBestCounsellors = computed(() => {
    return props.bestCounsellors.data?.length ? props.bestCounsellors.data : props.bestCounsellors
})
const computedLeadingCounsellors = computed(() => {
    return props.leadingCounsellors.data?.length ? props.leadingCounsellors.data : props.leadingCounsellors
})
const computedCanCreatePost = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    if (user.isAdmin) return true

    return (!!user.counsellor) ? true : false
})

function updateNewTherapy(value) {
    newTherapy.value = value
}

function updatePage(res, items) {
    if (res.data?.links?.next) items.value.page += 1
    else items.value.page = 0
}

function loadContent() {
    randomCounsellors.value.page = 1
    randomTherapies.value.page = 1
    posts.value.page = 1

    getRandomCounsellors()
    getRandomTherapies()
    getPosts()
}

async function getRandomCounsellors() {
    if (!randomCounsellors.value.page) return

    setGetting('counsellors')
    await axios.get(`${route('api.counsellors.random')}?page=${randomCounsellors.value.page}`)
        .then((res) => {
            console.log(res)
            if (randomCounsellors.value.page == 1)
                randomCounsellors.value.data = []
            
            randomCounsellors.value.data = [
                ...randomCounsellors.value.data,
                ...res.data.data,
            ]

            updatePage(res, randomCounsellors)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

async function getPosts() {
    if (!posts.value.page) return

    setGetting('posts')
    await axios.get(`${route('api.posts')}?page=${posts.value.page}`)
        .then((res) => {
            console.log(res)
            if (posts.value.page == 1)
                posts.value.data = []
            
            posts.value.data = [
                ...posts.value.data,
                ...res.data.data,
            ]

            updatePage(res, posts)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

function getRandomTherapies() {
    if (!randomTherapies.value.page) return

    setGetting('therapies')
    axios.get(`${route('api.therapies.random')}?page=${randomTherapies.value.page}`)
        .then((res) => {
            console.log(res)
            if (randomTherapies.value.page == 1)
                randomTherapies.value.data = []
            
            randomTherapies.value.data = [
                ...randomTherapies.value.data,
                ...res.data.data,
            ]

            updatePage(res, randomTherapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function createPost(post) {
    const counsellorId = usePage().props.auth.user?.counsellor?.id

    posts.value.data = [
        {...post, 
            status: 'sending',
            addedbyId: counsellorId,
            addedbyType: 'Counsellor',
        },
        ...posts.value.data
    ]
}

function updatePost(post, idx) {
    posts.value.data.splice(idx, 1, post)
}

function deletePost(idx) {
    posts.value.data.splice(idx, 1)
}

function updatePostById(post) {
    posts.value.data.splice(posts.value.data.findIndex((p) => p.id == post.id), 1, post)
}

function deletePostById(post) {
    posts.value.data.splice(posts.value.data.findIndex((p) => p.id == post.id), 1)
}

function showPost() {
    showModal('post')
}

</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout>
        <div class="pt-6 pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 flex justify-end">
                <HelpButton
                    title="get help on Home Page"
                    :page="'Home'"
                    class="mr-4"
                />
            </div>
            <div class="block space-y-4 md:flex justify-start items-start md:space-y-0 md:space-x-4">
                <div class="w-full md:w-[50%]">
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">Starred Counsellors (previous month)</div>
                            <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-4 flex justify-start items-center" v-if="computedBestCounsellors?.length">
                                <StarredCounsellorComponent
                                    v-for="(counsellor, idx) in computedBestCounsellors"
                                    :key="counsellor.id"
                                    :position="idx + 1"
                                    :counsellor="counsellor"
                                    :showStars="false"
                                    class="w-[250px] shrink-0"
                                />
                            </div>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">there are no best counsellors for the previous month.</div>
                        </div>
                    </div>
                    
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">Leading Counsellors (current month)</div>
                            <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-4 flex justify-start items-center" v-if="computedLeadingCounsellors?.length">
                                <StarredCounsellorComponent
                                    v-for="(counsellor, idx) in computedLeadingCounsellors"
                                    :key="counsellor.id"
                                    :position="idx + 1"
                                    :counsellor="counsellor"
                                    class="w-[250px] shrink-0"
                                />
                            </div>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">there are no leading counsellors for this month.</div>
                        </div>
                    </div>
                    
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">Counsellors</div>
                            <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-4 flex justify-start items-center" v-if="randomCounsellors.data?.length">
                                <StarredCounsellorComponent
                                    v-for="(counsellor) in randomCounsellors.data"
                                    :key="counsellor.id"
                                    :counsellor="counsellor"
                                    :showStars="false"
                                    class="w-[250px] shrink-0"
                                />
                            </div>
                            <div v-else-if="!getting.show && getting.type !== 'counsellors'" class="text-center text-sm w-full my-4 text-gray-600">we have no counsellors for now.</div>
                            <div v-if="getting.show && getting.type == 'counsellors'" class="text-center text-sm w-full text-green-600">getting more therapies.</div>
                            <div
                                v-if="randomCounsellors.page > 1 && !getting.show && getting.type !== 'counsellors'"
                                class="text-center text-sm w-fit mx-auto p-4 text-gray-600 cursor-pointer"
                                @click="getRandomCounsellors"
                            >...</div>
                        </div>
                    </div>
                    
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8" v-if="$page.props.auth.user">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">Your Recent Therapies</div>
                            <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center" v-if="recentTherapies?.length">
                                <MiniTherapyComponent
                                    v-for="therapy in recentTherapies"
                                    :key="therapy.id"
                                    :therapy="therapy"
                                    class="w-[250px] shrink-0"
                                />
                            </div>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies</div>
                        </div>
                    </div>
                    
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-8">
                        <div class="bg-white shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">Public Therapies</div>
                            <div class="px-6 flex min-h-[100px] justify-start py-4 items-start overflow-hidden overflow-x-auto space-x-5">
                                <template v-if="randomTherapies.data?.length">
                                    <MiniTherapyComponent
                                        v-for="therapy in randomTherapies.data"
                                        :key="therapy.id"
                                        :therapy="therapy"
                                        class="w-[250px] shrink-0"
                                    />
                                </template>
                                <div v-else-if="!getting.show && getting.type !== 'therapies'" class="text-center text-sm w-full text-gray-600">there are no therapies for public at the moment.</div>
                                <div v-if="getting.show && getting.type == 'therapies'" class="text-center text-sm w-full text-green-600">getting more therapies.</div>
                                <div
                                    v-if="randomTherapies.page > 1 && !getting.show && getting.type !== 'therapies'"
                                    class="text-center text-sm w-fit mx-auto p-4 text-gray-600 cursor-pointer"
                                    @click="getRandomTherapies"
                                >...</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="w-full md:w-[50%] shrink-0">
                    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900 flex justify-between items-center">
                                <div>Posts</div>
                                <PrimaryButton
                                    v-if="computedCanCreatePost"
                                    @click="() => showModal('create post')">create post</PrimaryButton>
                            </div>
                            <div class="m-2 p-2 overflow-hidden overflow-y-auto space-y-4" v-if="posts.data?.length">
                                <PostComponent
                                    v-for="(post, idx) in posts.data"
                                    :key="post.id"
                                    :position="idx + 1"
                                    :post="post"
                                    @created="(post) => updatePost(post, idx)"
                                    @updated="(post) => updatePost(post, idx)"
                                    @deleted="() => deletePost(idx)"
                                    class="w-[350px] md:w-[300px] lg:w-[350px] shrink-0 mx-auto mb-8"
                                />
                            </div>
                            <div v-else class="text-sm text-gray-600 w-full h-[200px] flex justify-center items-center">no posts yet</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <PostModal
        :show="modalData.show && modalData.type == 'post'"
        @close="closeModal"
        :post="post?.data ? post.data : post"
        @updated="updatePostById"
        @deleted="deletePostById"
    />

    <CreatePostModal
        :show="modalData.show && modalData.type == 'create post'"
        @close="closeModal"
        @created="createPost"
    />
</template>
