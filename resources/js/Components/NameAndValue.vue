<template>
    <div class="flex justify-start items-center mb-2 rounded text-slate-400 bg-slate-800 w-fit">
        <div class="text-sm p-2 mr-2 min-w-[110px] text-end">{{ name }}:</div>
        <div class="p-2 text-start min-w-[100px] text-slate-300">{{ value }}</div>
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