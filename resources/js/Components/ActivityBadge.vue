<script setup>

defineProps({
    name: {
        type: String,
    },
    value: {
        type: Number,
    },
})
</script>

<template>
    <div class="w-full sm:w-[60%] bg-stone-200 shadow rounded-lg p-2 flex justify-between items-start">
        <div class="text-sm text-stone-600">{{ name }}</div>
        <div class="w-16 h-16 rounded-full bg-stone-800 text-stone-200 p-2 flex items-center justify-center">
            <div class="text-stone-200 font-extrabold bg-stone-600 text-sm w-full h-full rounded-full justify-center items-center flex">
                <div>{{ value }}</div>
            </div>
        </div>
    </div>
</template>