<script setup>
import MiniGroupTherapyComponent from '@/Components/MiniGroupTherapyComponent.vue';
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue';
import useLoader from '@/Composables/useLoader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3'
import { computed } from 'vue';
import { onBeforeMount, provide, ref, watch } from 'vue';


const props = defineProps({
    therapies: {
        default: []
    },
    groupTherapies: {
        default: []
    }
})

const newTherapy = ref(null)
const newGroupTherapy = ref(null)
const wardTherapies = ref({
    individual: { data: [], page: 1 },
    group: { data: [], page: 1 }
})
const counsellorTherapies = ref({
    individual: { data: [], page: 1 },
    group: { data: [], page: 1 }
})
const therapies = ref({
    individual: { data: [], page: 1 },
    group: { data: [], page: 1 }
})
const { loader, showLoader, hideLoader } = useLoader()

onBeforeMount(() => {
    loadContent()
})

watch(() => newTherapy.value, () => {
    if (newTherapy.value)
        therapies.value.data = [newTherapy.value, ...therapies.value.data]
})
watch(() => newGroupTherapy.value, () => {
    if (newGroupTherapy.value)
        groupTherapies.value.data = [newGroupTherapy.value, ...groupTherapies.value.data]
})

const computedUser = computed(() => {
    return usePage().props.auth.user
})

provide('onCreatedNewTherapy', { newTherapy, updateNewTherapy })

function updateNewTherapy(value) {
    newTherapy.value = value
}

provide('onCreatedNewGroupTherapy', { newGroupTherapy, updateNewGroupTherapy })

function updateNewGroupTherapy(value) {
    newGroupTherapy.value = value
}

async function getIndividualCounsellorTherapies() {
    if (!counsellorTherapies.value.individual.page || !computedUser.value?.counsellor) return

    showLoader('counsellor therapies')
    await axios.get(route('api.therapies.counsellor', {page: counsellorTherapies.value.individual.page}))
        .then((res) => {
            console.log(res)
            if (counsellorTherapies.value.individual.page == 1)
                counsellorTherapies.value.individual.data = []
            
            counsellorTherapies.value.individual.data = [
                ...counsellorTherapies.value.individual.data,
                ...res.data.data,
            ]

            updatePage(res, counsellorTherapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            hideLoader()
        })
}

async function getIndividualWardTherapies() {
    if (!wardTherapies.value.individual.page || !computedUser.value?.isGuardian) return

    showLoader('ward therapies')
    await axios.get(route('api.therapies.ward', {page: wardTherapies.value.individual.page}))
        .then((res) => {
            console.log(res)
            if (wardTherapies.value.individual.page == 1)
                wardTherapies.value.individual.data = []
            
            wardTherapies.value.individual.data = [
                ...wardTherapies.value.individual.data,
                ...res.data.data,
            ]

            updatePage(res, wardTherapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            hideLoader()
        })
}

async function getIndividualTherapies() {
    if (!therapies.value.individual.page) return

    showLoader('user therapies')
    await axios.get(route('api.therapies.user', {page: therapies.value.individual.page}))
        .then((res) => {
            console.log(res)
            if (therapies.value.individual.page == 1)
                therapies.value.individual.data = []
            
            therapies.value.individual.data = [
                ...therapies.value.individual.data,
                ...res.data.data,
            ]

            updatePage(res, therapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            hideLoader()
        })
}

async function getGroupCounsellorTherapies() {
    if (!counsellorTherapies.value.group.page || !computedUser.value?.counsellor) return

    showLoader('counsellor therapies')
    await axios.get(route('api.group.therapies.counsellor', {page: counsellorTherapies.value.group.page}))
        .then((res) => {
            console.log(res)
            if (counsellorTherapies.value.group.page == 1)
                counsellorTherapies.value.group.data = []
            
            counsellorTherapies.value.group.data = [
                ...counsellorTherapies.value.group.data,
                ...res.data.data,
            ]

            updatePage(res, counsellorTherapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            hideLoader()
        })
}

async function getGroupWardTherapies() {
    if (!wardTherapies.value.group.page || !computedUser.value?.isGuardian) return

    showLoader('ward therapies')
    await axios.get(route('api.group.therapies.ward', {page: wardTherapies.value.group.page}))
        .then((res) => {
            console.log(res)
            if (wardTherapies.value.group.page == 1)
                wardTherapies.value.group.data = []
            
            wardTherapies.value.group.data = [
                ...wardTherapies.value.group.data,
                ...res.data.data,
            ]

            updatePage(res, wardTherapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            hideLoader()
        })
}

async function getGroupTherapies() {
    if (!therapies.value.group.page) return

    showLoader('user therapies')
    await axios.get(route('api.group.therapies.user', {page: therapies.value.group.page}))
        .then((res) => {
            console.log(res)
            if (therapies.value.group.page == 1)
                therapies.value.group.data = []
            
            therapies.value.group.data = [
                ...therapies.value.group.data,
                ...res.data.data,
            ]

            updatePage(res, therapies)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            hideLoader()
        })
}

function loadContent() {
    wardTherapies.value.individual.page = 1
    wardTherapies.value.group.page = 1
    counsellorTherapies.value.individual.page = 1
    counsellorTherapies.value.group.page = 1
    therapies.value.individual.page = 1
    therapies.value.group.page = 1

    getIndividualCounsellorTherapies()
    getGroupCounsellorTherapies()
    getIndividualWardTherapies()
    getGroupWardTherapies()
    getIndividualTherapies()
    getGroupTherapies()
}

function updatePage(res, items, type = 'individual') {
    if (res.data?.links?.next) items.value[type].page += 1
    else items.value[type].page = 0
}
</script>

<template>
    <Head title="Therapies" />

    <AuthenticatedLayout>
        <div class="pt-6 pb-12">
                    
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mb-6" v-if="$page.props.auth.user?.counsellor">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 font-bold text-lg text-center">Counsellor Section</div>
                    <div>
                        <div 
                            v-if="loader.show && loader.type == 'counsellor therapies'" 
                            class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                        >getting counsellor therapies</div>
                        <div class="text-gray-900 px-4">Individual Therapies</div>
                        <div class="m-2 px-3 pb-6 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                            <template v-if="counsellorTherapies.individual.data?.length">
                                <MiniTherapyComponent
                                    v-for="therapy in counsellorTherapies.individual.data"
                                    :key="therapy.id"
                                    :therapy="therapy"
                                    :show-go-to="true"
                                    class="w-[250px] shrink-0"
                                />

                                <div
                                    title="get more counsellor therapies"
                                    @click="getIndividualCounsellorTherapies"
                                    v-if="counsellorTherapies.individual.page"
                                    class="cursor-pointer p-2 text-gray-600 font-bold"
                                >...</div>
                            </template>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies as a counsellor</div>
                        </div>
                    </div>
                    <div class="mt-6">
                        <div 
                            v-if="loader.show && loader.type == 'counsellor group therapies'" 
                            class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                        >getting counsellor group therapies</div>
                        <div class="text-gray-900 px-4">Group Therapies</div>
                        <div class="m-2 px-3 pb-6 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                            <template v-if="counsellorTherapies.group.data?.length">
                                <MiniGroupTherapyComponent
                                    v-for="therapy in counsellorTherapies.group.data"
                                    :key="therapy.id"
                                    :groupTherapy="therapy"
                                    :show-go-to="true"
                                    class="w-[250px] shrink-0"
                                />

                                <div
                                    title="get more counsellor therapies"
                                    @click="getGroupCounsellorTherapies"
                                    v-if="counsellorTherapies.group.page"
                                    class="cursor-pointer p-2 text-gray-600 font-bold"
                                >...</div>
                            </template>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no group therapies as a counsellor</div>
                        </div>
                    </div>
                </div>
            </div>
                    
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mb-6" v-if="$page.props.auth.user?.isGuardian">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 font-bold text-lg text-center">Ward Section</div>
                    <div>
                        <div 
                            v-if="loader.show && loader.type == 'ward therapies'" 
                            class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                        >getting user therapies</div>
                        <div class="text-gray-900 px-4">Individual Therapies</div>
                        <div class="m-2 px-3 pb-6 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                            <template v-if="wardTherapies.individual.data?.length">
                                <MiniTherapyComponent
                                    v-for="therapy in wardTherapies.individual.data"
                                    :key="therapy.id"
                                    :therapy="therapy"
                                    class="w-[250px] shrink-0"
                                />

                                <div
                                    title="get more therapies"
                                    @click="getIndividualWardTherapies"
                                    v-if="wardTherapies.individual.page"
                                    class="cursor-pointer p-2 text-gray-600 font-bold"
                                >...</div>
                            </template>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies from your wards</div>
                        </div>
                    </div>
                    <div class="mt-6">
                        <div 
                            v-if="loader.show && loader.type == 'ward therapies'" 
                            class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                        >getting ward group therapies</div>
                        <div class="text-gray-900 px-4">Group Therapies</div>
                        <div class="m-2 px-3 pb-6 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                            <template v-if="wardTherapies.group.data?.length">
                                <MiniGroupTherapyComponent
                                    v-for="therapy in wardTherapies.group.data"
                                    :key="therapy.id"
                                    :groupTherapy="therapy"
                                    class="w-[250px] shrink-0"
                                />

                                <div
                                    title="get more therapies"
                                    @click="getGroupWardTherapies"
                                    v-if="wardTherapies.group.page"
                                    class="cursor-pointer p-2 text-gray-600 font-bold"
                                >...</div>
                            </template>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no group therapies from your wards</div>
                        </div>
                    </div>
                </div>
            </div>
                    
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 font-bold text-lg text-center">User Section</div>
                    <div>
                        <div 
                            v-if="loader.show && loader.type == 'user therapies'" 
                            class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                        >getting user therapies</div>
                        <div class="text-gray-900 px-4">Individual Therapies</div>
                        <div class="m-2 px-3 pb-6 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                            <template v-if="therapies.individual.data?.length">
                                <MiniTherapyComponent
                                    v-for="therapy in therapies.individual.data"
                                    :key="therapy.id"
                                    :therapy="therapy"
                                    class="w-[250px] shrink-0"
                                />

                                <div
                                    title="get more therapies"
                                    @click="getIndividualTherapies"
                                    v-if="therapies.individual.page"
                                    class="cursor-pointer p-2 text-gray-600 font-bold"
                                >...</div>
                            </template>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no therapies as a user</div>
                        </div>
                    </div>
                    <div>
                        <div 
                            v-if="loader.show && loader.type == 'user therapies'" 
                            class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                        >getting user group therapies</div>
                        <div class="text-gray-900 px-4">Group Therapies</div>
                        <div class="m-2 px-3 pb-6 overflow-hidden overflow-x-auto space-x-5 flex justify-start items-center">
                            <template v-if="therapies.group.data?.length">
                                <MiniGroupTherapyComponent
                                    v-for="therapy in therapies.group.data"
                                    :key="therapy.id"
                                    :groupTherapy="therapy"
                                    class="w-[250px] shrink-0"
                                />

                                <div
                                    title="get more therapies"
                                    @click="getGroupTherapies"
                                    v-if="therapies.group.page"
                                    class="cursor-pointer p-2 text-gray-600 font-bold"
                                >...</div>
                            </template>
                            <div v-else class="text-center text-sm w-full my-4 text-gray-600">you have no group therapies as a user</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>