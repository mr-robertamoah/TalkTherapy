<script setup>
import { ref } from 'vue';


defineProps({
    met: {
        type: Boolean,
        default: false,
    },
    requirement: {
        type: String,
        default: '',
    },
    info: {
        type: String,
        default: '',
    }
})

const showInfo = ref(false)

function clickedRequirement() {
    showInfo.value = true
    setTimeout(() => {
        showInfo.value = false
    }, 7000)
}
</script>

<template>
    <div class="flex justify-center items-center cursor-pointer select-none" @click="clickedRequirement">
        <div v-if="met" class="mr-2 cursor-pointer w-6 h-6 p-2 rounded font-bold bg-green-600 text-green-300 flex justify-center items-center">
            <div>+</div>
        </div>
        <div v-else class="mr-2 cursor-pointer w-6 h-6 p-2 rounded font-bold bg-gray-600 text-gray-300 flex justify-center items-center">
            <div>-</div>
        </div>

        <div class="text-sm block cursor-pointer sm:flex justify-start items-center" :class="[met ? 'text-green-700' : 'text-gray-700']">
            <div class="mr-2 cursor-pointer">{{ requirement }}</div>
            <div v-if="!met && info && showInfo">({{ info }})</div>
        </div>
    </div>
</template>