<template>
    <div
        :class="{
            'bg-green-400': type == 'available',
            'bg-blue-400': type == 'next',
            'bg-gray-400': type == 'future'
        }"
        class="p-2 rounded"
    >
        <div class="text-gray-600 p-2 pr-6 rounded bg-white w-fit">{{ feature.name }}</div>
        <div 
            :class="{
                'bg-green-200 text-green-600': type == 'available',
                'bg-blue-200 text-blue-600': type == 'next',
                'bg-gray-200 text-gray-600': type == 'future'
            }"
            class="my-2 ml-10 rounded text-sm text-justify p-2"
        >{{ computedDescription }}</div>
        <div v-if="feature.descriptions?.length || feature.description?.length > 100" class="flex justify-start items-center my-3">
            <PrimaryButton class="text-xs p-1" @click="() => showMore = !showMore">show {{ showMore ? 'less' : 'more' }}</PrimaryButton>
        </div>
        <div v-if="showMore && feature.descriptions?.length" class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 bg-white space-x-3 rounded">
            <div
                v-for="(description, idx) in feature.descriptions"
                :key="idx"
                class="w-[200px] sm:w-[300px] p-2 text-xs text-gray-600 bg-gray-200 rounded shrink-0"
            >
                <div class="font-bold text-center">{{ description.title }}</div>
                <div class="my-2">{{ description.note }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import { computed } from 'vue';


const props = defineProps({
    feature: {
        required: true
    },
    type: {
        required: true
    }
})

const showMore = ref(false)

const computedDescription = computed(() => {
    return showMore.value 
        ? props.feature.description
        : (
            props.feature.description?.length > 100 
                ? props.feature.description.slice(0, 100) + '...'
                : props.feature.description.slice(0, 100)
        )
})
</script>