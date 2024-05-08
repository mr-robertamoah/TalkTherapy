<template>
    <div
        :class="{
            'bg-green-200': type == 'available',
            'bg-blue-200': type == 'next',
            'bg-gray-200': type == 'future'
        }"
        class="p-2 rounded"
    >
        <div class="text-gray-600 p-2 pr-6 rounded bg-white w-fit">{{ feature.name }}</div>
        <div 
            :class="{
                'bg-green-600': type == 'available',
                'bg-blue-600': type == 'next',
                'bg-gray-600': type == 'future'
            }"
            class="my-2 ml-10 rounded text-slate-200 text-sm text-justify p-2"
        >{{ feature.description }}</div>
        <div v-if="feature.descriptions?.length" class="flex justify-start items-center my-3">
            <PrimaryButton class="text-xs p-1" @click="() => showMore = !showMore">show {{ showMore ? 'less' : 'more' }}</PrimaryButton>
        </div>
        <div v-if="showMore" class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 bg-white space-x-3 rounded">
            <div
                v-for="(description, idx) in feature.descriptions"
                :key="idx"
                class="w-[300px] p-2 text-sm text-gray-600 border border-gray-600 rounded shrink-0"
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


defineProps({
    feature: {
        required: true
    },
    type: {
        required: true
    }
})

const showMore = ref(false)
</script>