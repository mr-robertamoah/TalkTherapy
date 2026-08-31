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

// Inline style, not a dynamic Tailwind arbitrary-value class (`w-[${size}px]`): Tailwind's JIT
// compiler only generates CSS for class strings it can find via static source scanning, so any
// runtime-interpolated size not already present verbatim somewhere in the scanned source (see the
// safelist hack in AuthenticatedLayout.vue) silently gets NO width/height rule at all -- the
// element renders unconstrained at its image's intrinsic size. Caught in practice during TT-10.5
// (SCRUM-187) with size=64, which wasn't safelisted. Inline style has no such requirement and
// works for every size without needing to extend a safelist each time a new one is used.
const sizeStyle = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }))

const computedPadding = computed(() => {
    let padding = 'p-2'
    if (props.size < 50) padding = 'p-[2px]'

    return padding
})
</script>

<template>
    <div class="rounded-full bg-white aspect-square" :style="sizeStyle" :class="computedPadding">
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