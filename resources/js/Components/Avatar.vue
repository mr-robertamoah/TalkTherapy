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
    return `w-[${props.size}px] h-[${props.size}px] rounded-full bg-white aspect-square`
})

const computedPadding = computed(() => {
    let padding = 'p-2'
    if (props.size < 50) padding = 'p-[2px]'

    return padding
})
</script>

<template>
    <div :class="`${classes} ${computedPadding}`" >
        <div class="w-full h-full bg-gray-300 rounded-full flex items-center justify-center overflow-hidden" :class="computedPadding">
            <img 
                v-if="src.length"
                :src="src" :alt="alt"
                class="object-cover w-full h-full text-xs"
            >
            <div v-else class="w-full h-full flex justify-center items-center text-sm text-gray-600">{{ avatarText }}</div>
        </div>
    </div>
</template>