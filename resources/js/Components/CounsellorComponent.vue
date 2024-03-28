<script setup>
import { ref } from 'vue';
import Avatar from './Avatar.vue';
import CounsellorModal from './CounsellorModal.vue';

defineProps({
    counsellor: {
        default: null
    },
    hasView: {
        type: Boolean,
        default: true
    },
    online: {
        type: Boolean,
        default: false
    }
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
            <Avatar :avatar-text="'...'" :size="40" :src="counsellor?.avatar ?? ''"/>
            <div class="text-gray-600 flex justify-start items-center ml-2">
                <div class="capitalize mr-2">{{ counsellor.name }}</div>
                <div>{{ counsellor.username ? `(${counsellor.username})` : '' }}</div>
            </div>
        </div>
        <div class="mt-3 flex justify-end items-center" v-if="hasView">
            <div
                @click="() => view = true"
                class="p-2 bg-blue-700 text-blue-200 cursor-pointer tracking-wide rounded min-w-[80px] text-center hover:bg-blue-400 hover:text-blue-700 transition duration-75">view</div>
        </div>
        <div class="flex justify-end" v-if="online">
            <div 
                class="mx-2 w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2 bg-green-700"
            >
                <div 
                    class="w-full h-full rounded-full bg-green-300"
                ></div>
            </div>
        </div>
    </div>

    <CounsellorModal
        :show="view"
        @close="() => view = false"
        :counsellor="counsellor"
    />
</template>