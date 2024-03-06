<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const textbox = ref(null);

onMounted(() => {
    if (textbox.value.hasAttribute('autofocus')) {
        textbox.value.focus();
    }
});

defineExpose({ focus: () => textbox.value.focus() });
</script>

<template>
    <textarea
        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
        v-model="model"
        ref="textbox"
    ></textarea>
</template>
