<script setup>
import { ref } from 'vue';
import Avatar from './Avatar.vue';

defineProps({
    user: {
        default: null
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
        class="w-full max-w-[400px] bg-stone-200 p-2 rounded select-none"
    >
        <div class="flex justify-start items-center mb-3 cursor-pointer overflow-hidden overflow-x-auto p-2">
            <Avatar class="shrink-0" v-if="user.avatar" :avatar-text="'...'" :size="40" :src="user?.avatar ?? ''"/>
            <div class="text-gray-600 flex justify-start items-center ml-2 shrink-0 text-xs sm:text-sm md:text-base">
                <div class="capitalize mr-2">{{ user.fullName }}</div>
                <div v-if="user.username" class="text-ellipsis text-nowrap">({{ user.username }})</div>
            </div>
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
        <slot></slot>
    </div>
</template>