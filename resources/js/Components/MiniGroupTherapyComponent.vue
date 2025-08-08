<script setup>
import { computed, ref } from "vue"
import ActivityBadge from "./ActivityBadge.vue";
import CounsellorComponent from "./CounsellorComponent.vue";
import StyledLink from "./StyledLink.vue";
import { usePage } from "@inertiajs/vue3";

const showDetails = ref(false)

const props = defineProps({
    groupTherapy: {
        default: null,
    },
    showGoTo: {
        default: false,
    }
})

const computedAbout = computed(() => {
    return props.groupTherapy?.about ? 
        (props.groupTherapy.about.length > 120 ? 
            props.groupTherapy.about.slice(0, 120) + '...' : 
            props.groupTherapy.about) : 
        'No description available'
})
const computedIsParticipant = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    if (
        user?.id == props.groupTherapy?.userId ||
        user?.id == props.groupTherapy?.counsellor?.userId
    ) return true

    return false
})
const computedIsUser = computed(() => {
    const user = usePage().props.auth.user

    if (!user) return false

    if (
        user?.id == props.groupTherapy?.userId
    ) return true

    return false
})
const computedAnonymity = computed(() => {
    return props.groupTherapy.anonymous ? 'users will automatically be anonymous' : 'users are not anonymous by default'
})
const computedAllowAnyone = computed(() => {
    return props.groupTherapy.allowAnyone ? 'any user can join without sending a request' : 'users will have to send a request before join'
})
const computedCanViewPage = computed(() => {
    return props.showGoTo || computedIsParticipant.value || props.groupTherapy.public || usePage().props.auth.user?.isAdmin
})

</script>

<template>
    <div class="bg-gradient-to-br from-teal-50 to-gray-100 rounded-xl p-5 shadow-lg border border-teal-200 hover:shadow-xl transition-all duration-300 hover:scale-105 min-w-[300px]">
        <!-- Type Badge -->
        <div class="flex justify-between items-start mb-4">
            <div class="bg-orange-600 text-white px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wide">
                Group Therapy
            </div>
            <div class="flex gap-1">
                <span v-if="groupTherapy.public" class="bg-gray-100 text-gray-700 px-2 py-1 rounded-full text-xs font-medium border">Public</span>
                <span v-if="groupTherapy.anonymous" class="bg-gray-100 text-gray-700 px-2 py-1 rounded-full text-xs font-medium border">Anonymous</span>
            </div>
        </div>

        <!-- Title -->
        <h3 class="font-bold text-gray-800 text-lg mb-3 break-words line-clamp-2 leading-tight" :title="groupTherapy.name">{{ groupTherapy.name }}</h3>

        <!-- Group Info -->
        <div class="flex items-center gap-2 mb-3 text-sm">
            <div class="w-8 h-8 bg-orange-600 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"/>
                </svg>
            </div>
            <div class="font-semibold text-gray-700">
                <span>{{ groupTherapy.counsellorsCount || 0 }} counsellor{{ (groupTherapy.counsellorsCount || 0) === 1 ? '' : 's' }}</span>
                <span class="text-gray-400 mx-1">•</span>
                <span>Max {{ groupTherapy.maxUsers || '∞' }} users</span>
            </div>
        </div>

        <!-- Description -->
        <p class="text-sm text-gray-600 mb-4 line-clamp-3">{{ computedAbout }}</p>

        <!-- Stats -->
        <div class="flex justify-between items-center mb-4 text-xs text-gray-500">
            <span class="bg-white px-2 py-1 rounded-full">{{ groupTherapy.sessionsHeld || 0 }} sessions</span>
            <span>{{ groupTherapy.createdAt }}</span>
        </div>

        <!-- Action -->
        <div v-if="computedCanViewPage" class="text-center">
            <StyledLink 
                :text="'Join Group'" 
                :href="route('group.therapies.get', { groupTherapyId: groupTherapy.id})" 
                class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium text-sm transition-colors inline-block"
            />
        </div>
    </div>
</template>