<script setup>
import { computed } from 'vue';


const props = defineProps({
    src: {
        type: String,
        default: '',
    },
    alt: {
        type: String,
        default: '',
    },
    avatarText: {
        type: String,
        default: 'avatar',
    },
    size: {
        type: Number,
        default: 120,
    },
})

const classes = computed(() => {
    const mainSize =  props.size + (props.size / 4)

    return `w-[${props.size}px] h-[${props.size}px] p-2 rounded-full sm:w-[${mainSize}px] sm:h-[${mainSize}px] bg-white`
})
</script>

<template>
    <div :class="classes">
        <div class="w-full h-full bg-gray-300 rounded-full p-2 flex items-center justify-center">
            <img 
                v-if="src.length"
                :src="src" :alt="alt"
                class="object-cover rounded-full w-full h-full text-xs"
            >
            <div v-else class="w-full h-full flex justify-center items-center text-sm text-gray-600">{{ avatarText }}</div>
        </div>
    </div>
</template>