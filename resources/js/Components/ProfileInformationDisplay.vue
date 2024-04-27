<script setup>
const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    text: {
        type: String,
        required: true,
    },
    capitalize: {
        type: Boolean,
        default: true,
    }
})
</script>

<template>
    <div class="flex justify-start items-center my-4">
        <div class="w-[150px] shrink-0 text-right border-t-4 border-gray-600 px-2 pt-2 border-r-2 mr-2 text-sm">{{ label }}</div>
        <div
            :title="text ?? ''"
            :class="{'capitalize': capitalize, 'font-semibold tracking-wide': text, 'text-sm text-gray-500': !text}"
            class="w-full text-center border-b-2 border-gray-600 px-2 pb-2 border-l-4 ml-2 overflow-hidden overflow-ellipsis">{{text ? text : 'not set'}}</div>
    </div>
</template>