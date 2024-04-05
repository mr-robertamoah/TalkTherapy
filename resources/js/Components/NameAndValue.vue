<template>
    <div class="flex justify-start items-center mb-4">
        <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[110px] text-end">{{ name }}:</div>
        <div class="p-2 border-stone-600 text-start min-w-[100px]">{{ value }}</div>
    </div>
</template>

<script setup>

defineProps({
    name: {
        type: String,
    },
    value: {
        default: null,
    }
})
</script>