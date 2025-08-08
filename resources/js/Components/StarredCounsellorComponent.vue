<script setup>
import Avatar from './Avatar.vue';
import StarBadge from './StarBadge.vue';
import StyledLink from './StyledLink.vue';


defineProps({
    counsellor: {
        default: null
    },
    position: {
        type: Number
    },
    showStars: {
        type: Boolean,
        default: true
    }
})

</script>

<template>
    <div class="p-2 rounded shadow-sm bg-stone-100 relative" :class="{'mb-6': showStars}">
        <div class="p-2 text-gray-900 text-center flex items-center justify-center bg-gray-300 w-full h-[150px]">
            <img 
                :src="counsellor?.cover ?? ''" 
                :alt="'counsellor cover'"
                v-if="counsellor?.cover"
                class="w-full h-full object-cover rounded-b-lg"
            >
            <div v-else class="text-sm w-full h-full flex justify-center items-center text-gray-600 bg-white rounded-b-lg">no cover image</div>
        </div>

        <StyledLink class="absolute -top-1 right-1 z-[1] min-h-[44px] min-w-[44px] flex items-center justify-center px-3 py-2" :text="'visit profile'" :href="route('counsellor.show', { counsellorId: counsellor.id })"/>
        <div class="p-2 absolute top-0 left-0 w-full h-full bg-opacity-40 bg-gray-600">
            <div class="flex justify-start items-center">
                <Avatar :size="80" :src="counsellor?.avatar ?? ''" class="shrink-0" :alt="'...'"/>
                <div class="mt-2 text-start max-w-[90%] text-ellipsis ml-2 text-white text-sm font-bold capitalize">{{ counsellor.name }}</div>
            </div>

            <StarBadge
                :overall="counsellor?.overallStars"
                :month="counsellor?.stars"
                v-if="showStars"
                class="mt-2"
            />
            <div v-if="position" class="bg-stone-200 w-10 h-10 rounded-full p-2 mb-2 absolute bottom-1 right-1">
                <div class="bg-stone-700 text-sm font-semibold w-full h-full text-stone-200 rounded-full flex justify-center items-center">
                    <div>{{ position }}</div>
                </div>
            </div>
        </div>
    </div>
</template>