<script setup>
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3'
import { onBeforeMount, ref } from 'vue';


const props = defineProps({
    therapies: {
        default: []
    }
})

const counsellorTherapies = ref({ data: [], page: 1 })
const therapies = ref({ data: [], page: 1 })
const getting = ref({
    show: false,
    type: '',
})

onBeforeMount(() => {
    loadContent()
})

async function getCounsellorTherapies() {
    if (!counsellorTherapies.value.page || !usePage().props.auth.user?.counsellor) return

    setGetting('counsellor')
    await axios.get(`${route('api.therapies.counsellor')}?page=${counsellorTherapies.value.page}`)
        .then((res) => {
            console.log(res)
            if (counsellorTherapies.value.page == 1)
                counsellorTherapies.value.data = []
            
            counsellorTherapies.value.data = [
                ...counsellorTherapies.value.data,
                ...res.data.data,
            ]

            updatePage(res, counsellorTherapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

async function getTherapies() {
    if (!therapies.value.page) return

    setGetting('user')
    await axios.get(`${route('api.therapies.user')}?page=${therapies.value.page}`)
        .then((res) => {
            console.log(res)
            if (therapies.value.page == 1)
                therapies.value.data = []
            
            therapies.value.data = [
                ...therapies.value.data,
                ...res.data.data,
            ]

            updatePage(res, therapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            clearGetting()
        })
}

function loadContent() {
    counsellorTherapies.value.page = 1
    therapies.value.page = 1

    getCounsellorTherapies()
    getTherapies()
}

function updatePage(res, items) {
    if (res.data?.links?.next) items.value.page += 1
    else items.value.page = 0
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
}
</script>

<template>
    <Head title="Therapies" />

    <AuthenticatedLayout>
        <div class="pt-6 pb-12">
                    
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mb-6" v-if="$page.props.auth.user?.counsellor">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">Counsellor Therapies</div>
                    <div v-if="getting.show" class="text-center text-sm w-full my-4 text-green-600 bg-green-200">getting counsellor therapies</div>
                    <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                        <template v-if="counsellorTherapies.data?.length">
                            <MiniTherapyComponent
                                v-for="therapy in counsellorTherapies.data"
                                :key="therapy.id"
                                :therapy="therapy"
                                class="w-[250px] shrink-0"
                            />

                            <div
                                title="get more counsellor therapies"
                                @click="getCounsellorTherapies"
                                v-if="counsellorTherapies.page"
                                class="cursor-pointer p-2 text-gray-600 font-bold"
                            >...</div>
                        </template>
                        <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies as a counsellor</div>
                    </div>
                </div>
            </div>
                    
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">Therapies</div>
                    <div v-if="getting.show" class="text-center text-sm w-full my-4 text-green-600 bg-green-200">getting user therapies</div>
                    <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                        <template v-if="therapies.data?.length">
                            <MiniTherapyComponent
                                v-for="therapy in therapies.data"
                                :key="therapy.id"
                                :therapy="therapy"
                                class="w-[250px] shrink-0"
                            />

                            <div
                                title="get more therapies"
                                @click="getTherapies"
                                v-if="therapies.page"
                                class="cursor-pointer p-2 text-gray-600 font-bold"
                            >...</div>
                        </template>
                        <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies as a user</div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>