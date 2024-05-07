<script setup>
import { ref, watch } from 'vue';
import Avatar from './Avatar.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import LicenseComponent from './LicenseComponent.vue';
import Modal from './Modal.vue';
import FormLoader from './FormLoader.vue';


const props = defineProps({
    request: {
        default: null
    },
    show: {
        default: false,
        type: Boolean,
    }
})

const emits = defineEmits(['close', 'onResponse'])

const responding = ref(false)

watch(
    () => props.request?.status,
    () => {
        if (props.request?.status !== 'PENDING' && responding)
            responding.value = false
    }
)
watch(
    () => props.show,
    () => {
        if (props.show)
            responding.value = false
    }
)

function closeModal() {
    emits('close')
}

function clickedResponse(response) {
    responding.value = true
    emits('onResponse', response)
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
                <Avatar :avatar-text="'...'" :size="40" :src="request.counsellor.avatar ?? ''"/>
                <div class="text-gray-600 capitalize ml-2">{{ request.counsellor.name }} ({{ request.counsellor.username }})</div>
            </div>
            <div class="text-sm text-gray-600 text-center mt-4">
                {{ 
                    request.status == 'PENDING' 
                        ? 'Has sent a request to be verified.'
                        : (
                            request.status == 'ACCEPTED' 
                                ? 'Request for verification was accepted.'
                                : 'Request for verification was rejected'
                        )
                }}
            </div>
            <FormLoader class="mx-auto" :show="responding" :text="'responding to verification request'"/>
            <hr class="my-2">

            <div
                class="w-full p-2 rounded shadow-sm select-none h-[50vh] overflow-hidden overflow-y-auto"
            >
                <div class="text-sm rounded mb-4 bg-stone-100 p-2 w-full sm:w-[80%] mx-auto">
                    <div class="text-gray-600 flex justify-start items-center">
                        <div>Email:</div>
                        <div class="font-bold ml-2">{{ request.counsellor.email }}</div>
                    </div>
                    <div class="text-gray-600 flex justify-start items-center">
                        <div>Phone:</div>
                        <div class="font-bold ml-2">{{ request.counsellor.phone }}</div>
                    </div>
                </div>
                <div class="w-full sm:w-[80%] mx-auto">
                    <LicenseComponent
                        :license="request.nationalIdLicense"
                        class="mb-4"
                    />
                    <LicenseComponent
                        :license="request.otherLicense"
                    />
                </div>
            </div>

            <hr>
            <div class="mt-5 text-gray-600 text-center">Actions</div>
            <div class="mt-2 flex justify-end items-center" v-if="request.status == 'PENDING'">
                <PrimaryButton :disabled="responding" @click="() => clickedResponse('accepted')">accept</PrimaryButton>
                <DangerButton :disabled="responding" @click="() => clickedResponse('rejected')" class="ml-2">reject</DangerButton>
            </div>
            <div v-else class="mt-2 text-center text-sm text-gray-600 lowercase">request was {{ request.status }}</div>
        </div>
    </Modal>
</template>