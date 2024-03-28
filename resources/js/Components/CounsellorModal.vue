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
        <div
            class="w-full p-2 select-none"
        >
            <div class="flex justify-start items-center mb-3 cursor-pointer">
                <Avatar :avatar-text="'...'" :size="40" :src="counsellor.avatar ?? ''"/>
                <div class="text-gray-600 capitalize ml-2">{{ counsellor.name }} ({{ counsellor.username }})</div>
            </div>
            <hr class="my-2">

            <div
                class="w-full p-2 rounded shadow-sm select-none h-[70vh] overflow-hidden overflow-y-auto"
            >
                <div class="text-sm rounded mb-4 bg-stone-100 p-2 w-full sm:w-[80%] mx-auto">
                    <div class="text-gray-600 flex justify-start items-center">
                        <div>Email:</div>
                        <div class="font-bold ml-2">{{ counsellor.email }}</div>
                    </div>
                    <div class="text-gray-600 flex justify-start items-center">
                        <div>Phone:</div>
                        <div class="font-bold ml-2">{{ counsellor.phone }}</div>
                    </div>
                </div>

                
                <div class="text-sm rounded mb-4 bg-stone-100 p-2 w-full sm:w-[80%] mx-auto relative">
                    <div class="font-bold text-center text-gray-600 capitalize my-2">stats</div>
                    <div>
                        <FormLoader :show="loading" :text="'getting statistics'"/>
                    </div>

                    <div v-if="data.id && !loading" class="w-full">
                        <ActivityBadge
                            name="number of therapies"
                            :value="data.numberOfTherapies"
                            class="mb-4"
                        />
                        <ActivityBadge
                            name="number of group therapies"
                            :value="data.numberOfGroupTherapies"
                            class="mb-4 ml-6"
                        />
                        <ActivityBadge
                            name="number of free therapies"
                            :value="data.numberOfFreeTherapies"
                            class="mb-4"
                        />
                        <ActivityBadge
                            name="number of paid therapies"
                            :value="data.numberOfPaidTherapies"
                            class="mb-4 ml-6"
                        />
                        <ActivityBadge
                            name="number of free group therapies"
                            :value="data.numberOfFreeGroupTherapies"
                            class="mb-4"
                        />
                        <ActivityBadge
                            name="number of paid group therapies"
                            :value="data.numberOfPaidGroupTherapies"
                            class="mb-4 ml-6"
                        />
                        <ActivityBadge
                            name="number of sessions held"
                            :value="data.numberOfSessionsHeld"
                            class="mb-4"
                        />
                        <ActivityBadge
                            name="number of sessions created"
                            :value="data.numberOfSessionsCreated"
                            class="mb-4 ml-6"
                        />
                        <ActivityBadge
                            name="number of online sessions held"
                            :value="data.numberOfOnlineSessionsCount"
                            class="mb-4"
                        />
                        <ActivityBadge
                            name="number of therapies"
                            :value="data.numberOfInPersonSessionsCount"
                            class="mb-4"
                        />
                    </div>

                    <PrimaryButton v-if="!data.id && !loading" :disabled="loading" class="ml-auto my-2" @click="getCounsellorStats">
                        get stats
                    </PrimaryButton>
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