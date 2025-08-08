<script setup>
import { ref, watch } from 'vue';
import Avatar from './Avatar.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import LicenseComponent from './LicenseComponent.vue';
import Modal from './Modal.vue';
import FormLoader from './FormLoader.vue';
import axios from 'axios';
import ActivityBadge from './ActivityBadge.vue';


const props = defineProps({
    counsellor: {
        default: null
    },
    show: {
        default: false,
        type: Boolean,
    }
})

const emits = defineEmits(['close', 'onResponse'])

const loading = ref(false)
const data = ref({})

// watch(
//     () => props.request?.status,
//     () => {
//         if (props.request?.status !== 'PENDING' && responding)
//             responding.value = false
//     }
// )
watch(
    () => props.show,
    () => {
        if (props.show)
            data.value = {}
    }
)

function closeModal() {
    emits('close')
}

function clickedResponse(response) {
    responding.value = true
    emits('onResponse', response)
}

async function getCounsellorStats() {
    loading.value = true

    await axios.get(route('admin.counsellors.stats', {
        counsellorId: props.counsellor?.id
    }))
    .then((res) => {
        console.log(res)
        data.value = {...res.data.data}
    })
    .catch((err) => {
        console.log(err)
    })

    loading.value = false
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="w-full p-6 select-none">
            <!-- Header Section -->
            <div class="bg-gradient-to-r from-gray-50 to-stone-100 p-6 rounded-xl mb-6">
                <div class="flex items-center mb-4">
                    <Avatar :avatar-text="'...'" :size="60" :src="counsellor.avatar ?? ''"/>
                    <div class="ml-4">
                        <h2 class="text-2xl font-bold text-gray-800 capitalize">{{ counsellor.name }}</h2>
                        <p class="text-gray-600">@{{ counsellor.username }}</p>
                        <div class="flex items-center mt-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Verified Counsellor
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="h-[60vh] overflow-hidden overflow-y-auto space-y-6">
                <!-- Contact Information -->
                <div class="bg-gradient-to-br from-slate-50 to-slate-100 p-6 rounded-xl">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-6 bg-slate-600 rounded-full mr-3"></div>
                        <h3 class="text-lg font-semibold text-gray-800">Contact Information</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Email</span>
                            <p class="text-gray-800 font-medium">{{ counsellor.email }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Phone</span>
                            <p class="text-gray-800 font-medium">{{ counsellor.phone }}</p>
                        </div>
                    </div>
                </div>

                <!-- Statistics Section -->
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-6 rounded-xl relative">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-6 bg-gray-600 rounded-full mr-3"></div>
                        <h3 class="text-lg font-semibold text-gray-800">Professional Statistics</h3>
                    </div>
                    
                    <FormLoader :show="loading" :text="'Loading statistics...'"/>

                    <div v-if="data.id && !loading" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Total Therapies</span>
                            <p class="text-2xl font-bold text-gray-800">{{ data.numberOfTherapies }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Group Therapies</span>
                            <p class="text-2xl font-bold text-gray-800">{{ data.numberOfGroupTherapies }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Sessions Held</span>
                            <p class="text-2xl font-bold text-gray-800">{{ data.numberOfSessionsHeld }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Sessions Created</span>
                            <p class="text-2xl font-bold text-gray-800">{{ data.numberOfSessionsCreated }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Online Sessions</span>
                            <p class="text-2xl font-bold text-gray-800">{{ data.numberOfOnlineSessionsCount }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">In-Person Sessions</span>
                            <p class="text-2xl font-bold text-gray-800">{{ data.numberOfInPersonSessionsCount }}</p>
                        </div>
                    </div>

                    <div v-if="!data.id && !loading" class="text-center">
                        <PrimaryButton :disabled="loading" @click="getCounsellorStats">
                            Load Statistics
                        </PrimaryButton>
                    </div>
                </div>
            </div>

            <!-- <hr> -->
            <!-- <div class="mt-5 text-gray-600 text-center">Actions</div> -->
            <!-- <div class="mt-2 flex justify-end items-center" v-if="request.status == 'PENDING'">
                <PrimaryButton :disabled="responding" @click="() => clickedResponse('accepted')">accept</PrimaryButton>
                <DangerButton :disabled="responding" @click="() => clickedResponse('rejected')" class="ml-2">reject</DangerButton>
            </div>
            <div v-else class="mt-2 text-center text-sm text-gray-600 lowercase">request was {{ request.status }}</div> -->
        </div>
    </Modal>
</template>