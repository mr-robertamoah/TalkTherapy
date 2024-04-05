<template>
    <div class="fixed w-full z-10 left-0">
        <div
            v-bind="$attrs"
            class="rounded my-2 transition duration-100 mx-auto text-center w-[80%] py-1 px-2"
            :class="[show ? 'visible opacity-100 h-fit' : 'invisible opacity-0 h-0', danger ? 'text-red-700 bg-red-300' : 'text-green-700 bg-green-300']"
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
    danger: {
        type: Boolean,
        default: false,
    },
})
</script>