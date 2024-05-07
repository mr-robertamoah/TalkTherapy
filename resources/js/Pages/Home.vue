<script setup>
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue';
import StarredCounsellorComponent from '@/Components/StarredCounsellorComponent.vue';
import HelpButton from '@/Components/HelpButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeMount, provide, ref, watch } from 'vue';

const props = defineProps({
    recentTherapies: {
        default: []
    },
    bestCounsellors: {
        default: []
    },
    leadingCounsellors: {
        default: []
    }
})

const newTherapy = ref(null)
const getting = ref({ show: false, type: '' })
const recentTherapies = ref([])
const randomTherapies = ref({ data: [], page: 1 })
const randomCounsellors = ref({ data: [], page: 1 })

watch(() => newTherapy.value, () => {
    if (newTherapy.value)
        recentTherapies.value = [newTherapy.value, ...recentTherapies.value]
})

onBeforeMount(() => {
    if (props.recentTherapies?.length)
        recentTherapies.value = [...props.recentTherapies]
    
    if (props.recentTherapies?.data?.length)
        recentTherapies.value = [...props.recentTherapies.data]

    getRandomCounsellors()
    getRandomTherapies()
})

provide('onCreatedNewTherapy', { newTherapy, updateNewTherapy })

function updateNewTherapy(value) {
    newTherapy.value = value
}

function updatePage(res, items) {
    if (res.data?.links?.next) items.value.page += 1
    else items.value.page = 0
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

const computedBestCounsellors = computed(() => {
    return props.bestCounsellors.data?.length ? props.bestCounsellors.data : props.bestCounsellors
})

const computedLeadingCounsellors = computed(() => {
    return props.leadingCounsellors.data?.length ? props.leadingCounsellors.data : props.leadingCounsellors
})
</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout>
        <div class="pt-6 pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 flex justify-end">
                <HelpButton
                    title="get help on Home Page"
                    :page="'Home'"
                />
            </div>
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
                            class="w-[250px]"
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
                            class="w-[250px]"
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
                            class="w-[250px]"
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
                    <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-4 flex justify-start items-center" v-if="recentTherapies?.length">
                        <MiniTherapyComponent
                            v-for="therapy in recentTherapies"
                            :key="therapy.id"
                            :therapy="therapy"
                            class="w-[250px]"
                        />
                    </div>
                    <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies</div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-8">
                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">Public Therapies</div>
                    <div class="px-6 flex min-h-[100px] justify-start py-4 items-start overflow-hidden overflow-x-auto space-x-4">
                        <template v-if="randomTherapies.data?.length">
                            <MiniTherapyComponent
                                v-for="therapy in randomTherapies.data"
                                :key="therapy.id"
                                :therapy="therapy"
                                class="w-[250px]"
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
    </AuthenticatedLayout>
</template>
