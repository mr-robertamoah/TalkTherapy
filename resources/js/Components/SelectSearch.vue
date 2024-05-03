<script setup>
import { ref, watch, watchEffect } from 'vue';


const emits = defineEmits(['selected'])

const selectedOption = ref()

const props = defineProps({
    options: {
        type: Array,
        required: true,
    },
    label: {
        type: String,
        default: 'name',
    },
    value: {
        type: Object,
    },
});

watch(() => selectedOption.value, () => {
    emits('selected', selectedOption.value)
})
watchEffect(() => {
    if (!props.value) {
        selectedOption.value = undefined
        return
    }

    if (
        props.value[props.label] && !selectedOption.value ||
        props.value[props.label] !== selectedOption.value[props.label]
    )
        selectedOption.value = props.value
})

</script>

<template>
    <v-select
        class="new-styles border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm capitalize"
        v-model="selectedOption"
        :label="label"
        :options="options"
    >
    </v-select>
</template>

<style>
@import "vue-select/dist/vue-select.css";

#vs1__combobox {
    padding: 6px 4px 6px 2px;
    border-radius: 6px;
    --tw-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --tw-shadow-colored: 0 1px 2px 0 var(--tw-shadow-color);
    box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}

.vs--open .vs__dropdown-toggle {
    border-color: rgb(99 102 241 / 1);
    border-width: 2px;
    
    --tw-ring-opacity: 1;
    --tw-ring-color: rgb(99 102 241 / var(--tw-ring-opacity));
}

</style>
