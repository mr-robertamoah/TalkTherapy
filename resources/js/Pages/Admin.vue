<script setup>
import TestimonialComponent from '@/Components/TestimonialComponent.vue';
import AdminHowTosComponent from '@/Components/AdminHowTosComponent.vue';
import AdminUsersComponent from '@/Components/AdminUsersComponent.vue';
import Alert from '@/Components/Alert.vue';
import CounsellorComponent from '@/Components/CounsellorComponent.vue';
import TextInput from '@/Components/TextInput.vue';
import FormLoader from '@/Components/FormLoader.vue';
import VerificationRequest from '@/Components/VerificationRequest.vue';
import useAlert from '@/Composables/useAlert';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, reactive, watch } from 'vue';
import { default as _ } from 'lodash';

const { alertData, clearAlertData, setAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const props = defineProps({
    administrator: {
        default: null,
    }
})

const links = {
    users: 'users',
    counsellors: 'counsellors',
    therapies: 'therapies',
    groupTherapies: 'group therapies',
    statistics: 'statistics',
    others: 'others',
}

const loading = ref(false)
const currentLink = ref('')
const currentSubLink = ref('')
const filters = reactive({
    name: ''
})
const pages = reactive({
    users: {
        testimonials: 1, therapies: 1, 'group therapies': 1,
    },
    counsellors: {
        show: 1, 'verification requests': 1, therapies: 1, 'group therapies': 1,
    },
    therapies: {
        sessions: 1, discussions: 1,
    },
    'group therapies': {
        counsellors: 1, users: 1, sessions: 1,
    },
    statistics: {
        daily: 1, weekly: 1, monthly: 1, yearly: 1,
    },
})
const data = reactive({
    users: {
        show: false, testimonials: [], therapies: [], 'group therapies': [],
    },
    others: {
        howTos: false, contacts: false,
    },
    counsellors: {
        show: [], 'verification requests': [], therapies: [], 'group therapies': [],
    },
    therapies: {
        sessions: [], discussions: [],
    },
    'group therapies': {
        counsellors: [], users: [], sessions: [],
    },
    statistics: {
        daily: [], weekly: [], monthly: [], yearly: [],
    },
})

watch(() => currentSubLink.value, () => {
    if (typeof computedCallable.value == 'function') computedCallable.value()
})
watch(() => filters.name, () => {
    if (currentSubLink.value == 'show') {
        pages.counsellors.show = 1
        debouncedGetCounsellors()
    }
})

const computedSubLink = computed(() => {
    return {
        [links.users]: ['show', 'testimonials', 'therapies', 'group therapies'],
        [links.others]: ['contacts', 'howTos'],
        [links.counsellors]: ['show', 'verification requests', 'therapies', 'group therapies'],
        [links.therapies]: ['sessions', 'discussions'],
        [links.groupTherapies]: ['counsellors', 'users', 'sessions'],
        [links.statistics]: ['daily', 'weekly', 'monthly', 'yearly'],
    }[currentLink.value]
})
const loadingMessage = computed(() => {
    return {
        [links.users]: {'testimonials': 'getting testimonials', 'therapies': '', 'group therapies': ''},
        [links.counsellors]: {'show': 'getting counsellors', 'verification requests': 'getting verification requests for counsellors', 'therapies': '', 'group therapies': ''},
        [links.therapies]: {'sessions': '', 'discussions': ''},
        [links.groupTherapies]: {'counsellors': '', 'users': '', 'sessions': ''},
        [links.statistics]: {'daily': '', 'weekly': '', 'monthly': '', 'yearly': ''},
        [links.others]: {'howTos': '', 'contacts': ''},
    }[currentLink.value][currentSubLink.value]
})
const computedCallable = computed(() => {
    return {
        [links.users]: {'show': showUsers, 'testimonials': getTestimonials, 'therapies': '', 'group therapies': ''},
        [links.counsellors]: {'show': getCounsellors, 'verification requests': getVerificationRequests, 'therapies': '', 'group therapies': ''},
        [links.therapies]: {'sessions': '', 'discussions': ''},
        [links.groupTherapies]: {'counsellors': '', 'users': '', 'sessions': ''},
        [links.statistics]: {'daily': '', 'weekly': '', 'monthly': '', 'yearly': ''},
        [links.others]: {'howTos': showHowTos, 'contacts': ''},
    }[currentLink.value][currentSubLink.value]
})
const computedHasData = computed(() => {
    if (data[currentLink.value] && data[currentLink.value][currentSubLink.value]) {
        const value = data[currentLink.value][currentSubLink.value]

        return (typeof value == 'boolean') ? value : value.length
    }

    return false
})
const computedPage = computed(() => {
    if (pages[currentLink.value] && pages[currentLink.value][currentSubLink.value])
        return pages[currentLink.value][currentSubLink.value]

    return 0
})

function updatePage(res) {
    if (res.data?.links?.next) pages[currentLink.value][currentSubLink.value] += 1
    else pages[currentLink.value][currentSubLink.value] = 0
}

function showUsers() {
    data.users.show = true
}

function showHowTos() {
    data.others.howTos = true
}

async function getTestimonials() {
    loading.value = true

    await axios.get(`${route('api.testimonials')}?page=${computedPage.value}`)
        .then((res) => {
            console.log(res)
            if (computedPage.value > 1) {
                data.users['testimonials'] = [...data.users['testimonials'], ...res.data.data]
                updatePage(res)
                return
            }

            data.users['testimonials'] = [...res.data.data]
            updatePage(res)
        })
        .catch((err) => {
            console.log(err)

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 5000
            })
        })

    loading.value = false
}

async function getVerificationRequests() {
    if (!computedPage.value) return

    loading.value = true
    await axios.get(`${route('admin.verification.requests')}?page=${computedPage.value}`)
        .then((res) => {
            console.log(res)
            if (computedPage.value > 1) {
                data.counsellors['verification requests'] = [...data.counsellors['verification requests'], ...res.data.data]
                updatePage(res)
                return
            }

            data.counsellors['verification requests'] = [...res.data.data]
            updatePage(res)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            loading.value = false
        })
}

const debouncedGetCounsellors = _.debounce(() => {
    getCounsellors()
}, 1000)

async function getCounsellors() {
    if (!computedPage.value) return
    let filterType = '', filterValue = ''

    if (filters.name) {
        filterType = 'name'
        filterValue = filters.name
    }

    loading.value = true
    await axios.get(`${route('admin.counsellors')}?page=${computedPage.value}&filterType=${filterValue}`)
        .then((res) => {
            console.log(res)
            if (computedPage.value > 1) {
                data.counsellors.show = [...data.counsellors.show, ...res.data.data]
                updatePage(res)
                return
            }

            data.counsellors.show = [...res.data.data]
            updatePage(res)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            loading.value = false
        })
}

function clickedGetMore() {
    if (typeof computedCallable.value !== 'function') return
    
    computedCallable.value()
}

function respondToVerificationRequest(requestId, response) {
    axios.post(route('requests.respond', { requestId }), {
        response: response.toUpperCase()
    })
        .then((res) => {
            console.log(res)
            if (res.data.error) {
                setAlertData({
                    message: res.data.error,
                    time: 5000,
                    type: 'failed',
                    show: true
                })
                return
            }

            data.counsellors['verification requests'].splice(
                data.counsellors['verification requests'].findIndex((request) => request.id == res.data.request.id),
                1,
                res.data.request
            )
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            loading.value = false
        })
}
</script>

<template>
    <Head title="Administrator" />
    
    <AuthenticatedLayout>
        <div class="p-4 flex justify-between items-center w-full overflow-hidden overflow-x-auto my-2 bg-white">
            <div
                v-for="(link, idx) in links"
                :key="idx"
                @click="() => {
                    currentLink = link
                }"
                :class="[currentLink == link ? 'bg-gray-700 text-gray-200 hover:bg-gray-600 border-0' : 'hover:border-gray-700 hover:rounded-none']"
                class="text-sm text-nowrap uppercase tracking-wider cursor-pointer rounded p-2 transition duration-75 mr-2 border-b-2 border-transparent"
            >{{ link }}</div>
        </div>

        <div v-if="currentLink">
            <div class="w-full flex justify-center items-center bg-gray-700 p-4">
                <div
                    class="mr-2 text-sm capitalize rounded py-1 px-2 cursor-pointer transition duration-75 border-b-2 border-transparent"
                    v-for="(subLink, idx) in computedSubLink"
                    :class="[currentSubLink == subLink ? 'bg-stone-200 hover:text-stone-700 hover:bg-stone-300 border-0' : 'hover:border-stone-200 text-gray-200 hover:rounded-none']"
                    :key="idx"
                    @click="() => {
                        currentSubLink = subLink
                    }"
                >{{ subLink }}</div>
            </div>
            <div></div>
        </div>

        <div class="my-12 w-full sm:w-[90%] md:w-[75%] mx-auto flex flex-col justify-center items-center p-4 rounded-md bg-white" v-else>
            <div class="w-fit bg-gradient-to-r from-green-800 to-stone-600 bg-clip-text text-transparent text-2xl">Welcome {{ $page.props.auth.user?.fullName }}</div>
            <div class="lowercase text-sm text-gray-600 my-4">you are a {{ administrator.type }} administrator</div>
        </div>

        <div v-if="currentLink && !currentSubLink" class="my-6 rounded w-full sm:w-[80%] mx-auto flex justify-center items-center text-sm font-bold text-gray-600 h-[30vh] bg-white">
            <div>Hello, please select a sub link</div>
        </div>

        <div v-if="computedHasData || loading" class="my-12 relative w-full sm:w-[90%] md:w-[75%] mx-auto flex flex-col justify-center space-y-3 items-center p-4 rounded-md bg-white">
            <FormLoader class="mx-auto" :show="loading" :text="loadingMessage ?? ''"/>
            
            <template v-if="currentLink == 'counsellors' && currentSubLink == 'verification requests'">
                <VerificationRequest
                    v-for="request in data.counsellors['verification requests']"
                    :key="request.id"
                    :request="request"
                    @on-response="(response) => respondToVerificationRequest(request.id, response)"
                />
            </template>
            
            <template v-else-if="currentLink == 'users' && currentSubLink == 'testimonials'">
                <TestimonialComponent
                    v-for="testimonial in data.users.testimonials"
                    :key="testimonial.id"
                    :testimonial="testimonial"
                    class="w-full"
                />
            </template>
            
            <template v-else-if="currentLink == 'users' && currentSubLink == 'show'">
                <AdminUsersComponent
                    :show="!!data.users.show"
                    class="w-full"
                />
            </template>
            
            <template v-else-if="currentLink == 'others' && currentSubLink == 'howTos'">
                <AdminHowTosComponent
                    :show="!!data.others.howTos"
                    class="w-full"
                />
            </template>
            
            <template v-else-if="currentLink == 'counsellors' && currentSubLink == 'show'">
                <div>
                    <TextInput
                        v-model="filters.name"
                        placeholder="search by name/username"
                        class="w-[90%] mx-auto"
                    />
                </div>
                <CounsellorComponent
                    v-for="counsellor in data.counsellors.show"
                    :key="counsellor.id"
                    :counsellor="counsellor"
                />
            </template>

            <div
                v-if="computedPage && !loading"
                @click="clickedGetMore"
                class="text-2xl text-gray-600 cursor-pointer p-2 text-center my-4"
                title="get more">...</div>
        </div>
        
        <Alert
            :show="alertData.show"
            :type="alertData.type"
            :message="alertData.message"
            :time="alertData.time"
            @close="clearAlertData"
        />
    </AuthenticatedLayout>
</template>