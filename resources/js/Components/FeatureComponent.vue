<template>
    <div
        :class="computedBg + ` ${showMore ? 'pt-8' : ''}`"
        class="p-2 rounded relative transition-all duration-150 bg-gradient-to-br"
    >
        <div class="w-full"
            :class="[showMore ? 'absolute -top-5 mx-auto font-bold' : '']"
        >
            <div 
                class="text-gray-600 p-2 pr-6 rounded bg-white w-fit transition-all duration-150"
                :class="[showMore ? 'mx-auto' : 'ml-0']"
            >{{ feature.name }}</div>
        </div>
        <div 
            class="my-4 p-2 bg-white rounded"
            :class="[
                showMore
                ? '-mr-8 ml-8 shadow sm:text-justify'
                : 'text-sm text-pretty'
            ]"
        >{{ computedDescription }}</div>
            
        <div v-if="feature.descriptions?.length || feature.description?.length > 100" class="flex justify-start items-center my-3">
            <PrimaryButton class="text-xs p-1" @click="() => showMore = !showMore">show {{ showMore ? 'less' : 'more' }}</PrimaryButton>
        </div>
        <div v-if="showMore && feature.descriptions?.length" class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 space-x-3 rounded">
            <div
                v-for="(description, idx) in feature.descriptions"
                :key="idx"
                class="w-[200px] sm:w-[300px] p-2 text-xs text-white bg-gradient-to-br from- to-violet-500 rounded shrink-0"
            >
                <div class="font-bold text-center">{{ description.title }}</div>
                <div class="my-2">{{ description.note }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import PrimaryButton from './PrimaryButton.vue';


const props = defineProps({
    feature: {
        required: true
    },
    type: {
        required: true
    }
})

const showMore = ref(false)

const computedBg = computed(() => {
    return {
        'available': 'from-teal-600 via-teal-600 to-green-300',
        'next': 'from-blue-600 via-sky-600 to-blue-300',
        'future': 'from-fuchsia-400 via-pink-400 to-fuchsia-300',
    }[props.type]
})
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