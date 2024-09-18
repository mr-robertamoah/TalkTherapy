<template>
    <div
        :class="computedBg + ` pt-8 shadow-sm bg-white h-fit`"
        class="p-2 rounded relative transition-all duration-150 bg-gradient-to-br"
    >
        <div class="w-full absolute -top-5 right-0 mx-auto font-bold">
            <div class="relative w-fit mx-auto">
                <div 
                    class="text-gray-600 p-2 mx-auto text-center w-fit px-6 rounded bg-white"
                >{{ feature.name }}</div>
                <div
                    class="w-full bottom-0 absolute h-1 rounded"
                    :class="{
                        'bg-teal-600': feature.featureType == FEATURE_TYPES.available,
                        'bg-blue-600': feature.featureType == FEATURE_TYPES.next,
                        'bg-fuchsia-600': feature.featureType == FEATURE_TYPES.future,
                    }"
                ></div>
            </div>
        </div>

        <div
            class="flex justify-start items-center w-fit px-2 py-1 rounded mx-auto mt-4 mb-6 gap-2"
            :class="{
                'bg-teal-200': feature.featureType == FEATURE_TYPES.available,
                'bg-blue-200': feature.featureType == FEATURE_TYPES.next,
                'bg-fuchsia-200': feature.featureType == FEATURE_TYPES.future,
            }"
        >
            <div
                class="w-2 h-2 rounded-full"
                :class="{
                    'bg-teal-600': feature.featureType == FEATURE_TYPES.available,
                    'bg-blue-600': feature.featureType == FEATURE_TYPES.next,
                    'bg-fuchsia-600': feature.featureType == FEATURE_TYPES.future,
                }"
            ></div>
            <div
                class="text-sm lowercase"
                :class="{
                    'text-teal-600': feature.featureType == FEATURE_TYPES.available,
                    'text-blue-600': feature.featureType == FEATURE_TYPES.next,
                    'text-fuchsia-600': feature.featureType == FEATURE_TYPES.future,
                }"
            >{{ feature.featureType }} feature</div>
        </div>
        <hr>

        <div 
            class="my-4 p-2 text-sm text-pretty"
        >{{ computedDescription }} <span
            class="text-sm text-blue-500 cursor-pointer hover:underline"
            v-on:click="() => showMoreDescription = !showMoreDescription"
        >show {{ showMoreDescription ? "less" : "more"}}</span></div>

        <div 
            v-on:click="() => showMore = !showMore"
            v-if="feature.types?.length"
            class="p-2 space-x-3 rounded cursor-pointer text-gray-600 w-fit mx-auto text-sm">
            {{ showMore ? 'hide' : 'show' }} types
        </div>
        <div
            v-if="showMore && feature.types?.length"
            class="grid grid-cols-1 gap-5 rounded bg-white"
        >
            <div
                v-for="(type, idx) in feature.types"
                :key="idx"
                :class="[
                    feature.featureType == FEATURE_TYPES.available ? 'bg-teal-600' : 'bg-blue-600'
                ]"
                class="w-[90%] mx-auto p-2 text-xs text-white bg-gradient-to-br from- to-violet-500 rounded shrink-0"
            >
                <div class="font-bold text-center">{{ type.title }}</div>
                <div class="my-2">{{ type.note }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import useFeatures from '@/Composables/useFeatures';

const { FEATURE_TYPES } = useFeatures()

const props = defineProps({
    feature: {
        required: true
    },
    minDescriptionLength: {
        type: Number,
        default: 100
    }
})

const showMore = ref(false)
const showMoreDescription = ref(false)

const computedBg = computed(() => {
    console.log(props.feature.featureType)
    return {
        'available': 'shadow-teal-600',
        'next': 'shadow-blue-600',
        'future': 'shadow-fuchsia-600',
    }[props.feature.featureType?.toLowerCase()]
})
const computedDescription = computed(() => {
    return showMoreDescription.value 
        ? props.feature.description
        : (
            props.feature.description?.length > props.minDescriptionLength 
                ? props.feature.description.slice(0, props.minDescriptionLength) + '...'
                : props.feature.description.slice(0, props.minDescriptionLength)
        )
})
</script>