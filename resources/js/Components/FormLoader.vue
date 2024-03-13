<template>
    <div class="fixed w-full z-10">
        <div
            class="text-green-700 bg-green-300 rounded my-2 transition duration-100 text-center w-[80%] py-1 px-2 mx-auto"
            :class="[show ? 'visible opacity-100 h-fit' : 'invisible opacity-0 h-0']"
        >{{ text }}...</div>
    </div>
</template>

<script setup>
defineProps({
    text: {
        type: String,
        required: true,
    },
    show: {
        type: Boolean,
        required: true,
    },
})
</script>