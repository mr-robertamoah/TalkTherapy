<template>
    <div class="flex justify-start items-center">
        <div 
            class="mx-2 w-4 h-4 p-1 rounded-full flex justify-center items-center"
            :class="[condition ? 'bg-green-700' : 'bg-gray-700']"
        >
            <div 
                class="w-full h-full rounded-full"
                :class="[condition ? 'bg-green-300' : 'bg-gray-300']"
            ></div>
        </div>
        <div
            class="text-sm"
            :class="[condition ? 'text-green-700' : 'text-gray-700']"
        >{{ text }}</div>
    </div>
</template>

<script setup>

defineProps({
    text: {
        type: String,
    },
    condition: {
        type: Boolean,
    },
})
</script>