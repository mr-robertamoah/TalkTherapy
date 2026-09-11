<script setup>
import Avatar from './Avatar.vue';
import StarBadge from './StarBadge.vue';
import { Link } from '@inertiajs/vue3';


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
    <div class="rounded-xl shadow-lg bg-white border border-gray-100 relative overflow-hidden hover:shadow-xl transition-all duration-300" :class="{'mb-6': showStars}">
        <div class="relative w-full h-[150px]">
            <img 
                :src="counsellor?.cover ?? ''" 
                :alt="'counsellor cover'"
                v-if="counsellor?.cover"
                class="w-full h-full object-cover"
            >
            <div v-else class="text-sm w-full h-full flex justify-center items-center text-gray-500 bg-gradient-to-br from-blue-50 to-indigo-100">No cover image</div>
            
            <!-- Overlay -->
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/20 to-transparent">
                <!-- Profile Section -->
                <div class="absolute bottom-4 left-4 flex items-center space-x-3">
                    <Avatar :size="60" :src="counsellor?.avatar ?? ''" class="shrink-0 ring-2 ring-white" :alt="'...'"/>
                    <div class="text-white">
                        <div class="font-bold text-base capitalize truncate max-w-[120px]">{{ counsellor.name }}</div>
                        <div class="text-xs text-gray-200 opacity-90">Counsellor</div>
                    </div>
                </div>
                
                <!-- Position Badge -->
                <div v-if="position" class="absolute top-3 left-3 bg-blue-600 text-white w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold">
                    {{ position }}
                </div>
                
                <!-- Visit Profile Button -->
                <!-- Plain Link, not StyledLink -- StyledLink hardcodes its own bg/text classes on
                     its root element, and Vue's class-fallthrough merge doesn't let a passed-in
                     class reliably win over those (Tailwind's generated stylesheet order, not
                     attribute order, decides the winner) -- that previously left this pill's text
                     invisible. MiniTherapyComponent.vue/MiniGroupTherapyComponent.vue already use
                     a plain Link for this same reason wherever a fully custom style is needed. -->
                <div class="absolute top-3 right-3">
                    <Link
                        :href="route('counsellor.show', { counsellorId: counsellor.id })"
                        class="bg-white text-gray-800 px-3 py-1 rounded-full text-xs font-medium hover:bg-gray-100 transition-colors shadow-sm inline-block"
                    >View</Link>
                </div>
            </div>
        </div>
        
        <!-- Stars Section -->
        <div v-if="showStars" class="p-3 bg-white">
            <StarBadge
                :overall="counsellor?.overallStars"
                :month="counsellor?.stars"
                class="w-full"
            />
        </div>
    </div>
</template>