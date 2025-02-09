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
    if (showDetails.value && props.therapy.backgroundStory) return props.therapy.backgroundStory

    return props.therapy?.backgroundStory ? props.therapy?.backgroundStory.slice(0, 100) + '...' : ''
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
    <div class="rounded p-2 max-w-[350px] min-w-[250px] w-full shadow-sm bg-stone-200">
        <div class="text-center mx-auto font-bold w-fit bg-gradient-to-r from-slate-800 to-gray-600 my-2 bg-clip-text text-transparent capitalize">{{ therapy.name }}</div>
        
        <div class="my-2 w-full h-1 rounded bg-stone-400"></div>
        
        <div class="py-2 my-2 max-h-[300px] overflow-hidden overflow-y-auto">
            <div v-if="computedCanViewPage" class="p-2 flex justify-end">
                <StyledLink :text="'go to therapy'" :href="route('therapies.get', { therapyId: therapy.id})"/>
            </div>
            <div class="p-2">
                <CounsellorComponent
                    :counsellor="therapy.counsellor"
                    v-if="therapy.counsellor"
                    :visit-page="true"
                    :has-view="false"
                    class="bg-stone-200"
                />
                <div v-else class="text-sm text-gray-600 text-center p-4 rounded bg-stone-100">no counsellor yet</div>
            </div>

            <div class="p-2 mx-2 rounded bg-stone-100 my-4">
                <div
                    class="text-sm text-gray-600"
                >
                    <div>
                        <div class="mb-2 font-semibold">Background Story</div>
                        <div class="text-gray-600 text-justify max-h-[100px] py-2 overflow-hidden overflow-y-auto text-sm">{{ computedBackgroundStory }}</div>
                    </div>

                    <template
                        v-if="showDetails"
                    >
                        <div class="my-2 font-semibold">Other Information</div>
                        <div
                            class="mt-2 flex justify-start items-center" :class="[therapy.public ? 'text-green-700' : 'text-blue-700']">
                            <div 
                                class="w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2"
                                :class="[therapy.public ? 'bg-green-700' : 'bg-blue-700']"
                            >
                                <div 
                                    class="w-full h-full rounded-full"
                                :class="[therapy.public ? 'bg-green-300' : 'bg-blue-300']"
                                ></div>
                            </div>
                            <div>{{ therapy.public ? 'a ' : 'not a ' }}public therapy</div>
                        </div>

                        <div
                            class="mt-2 flex justify-start items-center" :class="[therapy.anonymous ? 'text-green-700' : 'text-blue-700']">
                            <div 
                                class="w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2"
                                :class="[therapy.anonymous ? 'bg-green-700' : 'bg-blue-700']"
                            >
                                <div 
                                    class="w-full h-full rounded-full"
                                :class="[therapy.anonymous ? 'bg-green-300' : 'bg-blue-300']"
                                ></div>
                            </div>
                            <div>{{ computedAnonymity }}</div>
                        </div>
                    </template>
                </div>
                <div
                    @click="() => showDetails = !showDetails"
                    class="underline text-sm mt-4 mb-2 text-end cursor-pointer text-blue-600"
                >{{ showDetails ? 'hide' : 'show' }} details</div>
            </div>
            <div class="p-2">
                <ActivityBadge
                    :name="'sessions held'"
                    :value="therapy.sessionsHeld"
                    class="mt-2"
                />  
            </div>
        </div>
        <div class="my-2 w-full h-1 rounded bg-stone-400"></div>

        <div class="text-xs text-end">
            {{ therapy.createdAt }}
        </div>
    </div>
</template>