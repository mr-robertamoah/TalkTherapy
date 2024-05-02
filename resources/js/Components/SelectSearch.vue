<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const props = defineProps({
    options: {
        type: Array,
        required: true,
    },
    defaultOption: {
        type: String,
        required: false,
    }
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <select
        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm capitalize"
        v-model="model"
        ref="input"
    >
        <optgroup class="p-2">
            
            <option v-if="defaultOption" disabled class="capitalize my-2">
                {{ defaultOption }}
            </option>
            <option
                v-for="(opt, idx) in options"
                :key="idx"
                :value="typeof opt == 'string' ? opt.toUpperCase() : opt.value?.toUpperCase()"
                class="mb-2 p-2 capitalize">{{ typeof opt == 'string' ? opt.toLowerCase() : opt.name.toLowerCase() }}</option>
        </optgroup>
    </select>
</template>

<style>
@import "vue-select/dist/vue-select.css";
</style>
