<script setup>
import { ref } from 'vue';
import Avatar from './Avatar.vue';
import VerificationRequestModal from './VerificationRequestModal.vue';

defineProps({
    request: {
        default: null
    },
})

const emits = defineEmits(['onResponse'])

const view = ref(false)

function clickedResponse(response) {
    emits('onResponse', response)
}
</script>

<template>
    <div
        class="w-full max-w-[400px] bg-stone-200 p-2 rounded shadow-sm select-none"
    >
        <div class="flex justify-start items-center mb-3 cursor-pointer">
            <Avatar :avatar-text="'...'" :size="40" :src="request.counsellor.avatar ?? ''"/>
            <div class="text-gray-600 capitalize ml-2">{{ request.counsellor.name }} ({{ request.counsellor.username }})</div>
        </div>
        <div class="text-sm text-gray-600 text-center">
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
        <div class="mt-3 flex justify-end items-center">
            <div
                @click="() => view = true"
                class="p-2 bg-blue-700 text-blue-200 cursor-pointer tracking-wide rounded min-w-[80px] text-center hover:bg-blue-400 hover:text-blue-700 transition duration-75">view</div>
        </div>
    </div>

    <VerificationRequestModal
        :show="view"
        @close="() => view = false"
        :request="request"
        @on-response="(response) => clickedResponse(response)"
    />
</template>