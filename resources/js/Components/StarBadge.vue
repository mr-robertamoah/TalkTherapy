<script setup>

defineProps({
    overall: {
        default: 0,
        type: Number
    },
    month: {
        default: 0,
        type: Number
    },
})
</script>

<template>
    <div class="w-[100px] h-[100px] bg-white shadow-md border-t-2 border-gray-300 flex justify-center items-center flex-col">
        <div class="flex w-full text-xs">
            <div class="w-[50%] text-center" title="current month">M</div>
            <div class="w-1 h-full bg-slate-600"></div>
            <div class="w-[50%] text-center" title="overall">O</div>
        </div>
        <div>stars</div>
        <div class="flex w-full text-xs">
            <div class="w-[50%] text-center">{{ month }}</div>
            <div class="w-1 h-full bg-slate-600"></div>
            <div class="w-[50%] text-center">{{ overall }}</div>
        </div>
    </div>
</template>