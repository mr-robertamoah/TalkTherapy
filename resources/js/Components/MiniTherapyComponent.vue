<script setup>
import { computed, ref } from "vue"
import ActivityBadge from "./ActivityBadge.vue";
import CounsellorComponent from "./CounsellorComponent.vue";
import StyledLink from "./StyledLink.vue";
import { usePage } from "@inertiajs/vue3";

const showDetails = ref(false)

const props = defineProps({
    therapy: {
        default: null,
    },
    showGoTo: {
        default: false,
    }
})

const computedBackgroundStory = computed(() => {
    return props.therapy?.backgroundStory ? 
        (props.therapy.backgroundStory.length > 120 ? 
            props.therapy.backgroundStory.slice(0, 120) + '...' : 
            props.therapy.backgroundStory) : 
        'No description available'
})
const computedIsParticipant = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    if (
        user?.id == props.therapy?.userId ||
        user?.id == props.therapy?.counsellor?.userId
    ) return true

    return false
})
const computedIsUser = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    if (
        user?.id == props.therapy?.userId
    ) return true

    return false
})
const computedAnonymity = computed(() => {
    const startOfMessage = computedIsUser.value ? 'you are' : 'user is'
    
    return `${startOfMessage} ${ props.therapy.anonymous ? '' : 'not ' }anonymous`
})
const computedCanViewPage = computed(() => {
    return props.showGoTo || computedIsParticipant.value || props.therapy.public || usePage().props.auth.user?.isAdmin
})

</script>

<template>
    <div class="bg-gradient-to-br from-slate-50 to-zinc-100 rounded-xl p-5 shadow-lg border border-zinc-200 hover:shadow-xl transition-all duration-300 hover:scale-105 min-w-[300px]">
        <!-- Type Badge -->
        <div class="flex justify-between items-start mb-4">
            <div class="bg-blue-600 text-white px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wide">
                Individual Therapy
            </div>
            <div class="flex gap-1">
                <span v-if="therapy.public" class="bg-gray-100 text-gray-700 px-2 py-1 rounded-full text-xs font-medium border">Public</span>
                <span v-if="therapy.anonymous" class="bg-gray-100 text-gray-700 px-2 py-1 rounded-full text-xs font-medium border">Anonymous</span>
            </div>
        </div>

        <!-- Title -->
        <h3 class="font-bold text-gray-800 text-lg mb-3 break-words line-clamp-2 leading-tight" :title="therapy.name">{{ therapy.name }}</h3>

        <!-- Counsellor -->
        <div class="flex items-center gap-2 mb-3 text-sm">
            <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                </svg>
            </div>
            <span class="font-semibold text-gray-700">{{ therapy.counsellor?.user?.name || 'No counsellor assigned' }}</span>
        </div>

        <!-- Description -->
        <p class="text-sm text-gray-600 mb-4 line-clamp-3">{{ computedBackgroundStory }}</p>

        <!-- Stats -->
        <div class="flex justify-between items-center mb-4 text-xs text-gray-500">
            <span class="bg-white px-2 py-1 rounded-full">{{ therapy.sessionsHeld || 0 }} sessions</span>
            <span>{{ therapy.createdAt }}</span>
        </div>

        <!-- Action -->
        <div v-if="computedCanViewPage" class="text-center">
            <StyledLink 
                :text="'Join Therapy'" 
                :href="route('therapies.get', { therapyId: therapy.id})" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm transition-colors inline-block"
            />
        </div>
    </div>
</template>