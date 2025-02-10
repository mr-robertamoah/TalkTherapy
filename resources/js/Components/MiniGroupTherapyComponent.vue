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
    if (showDetails.value && props.groupTherapy.about) return props.groupTherapy.about

    return props.groupTherapy?.about ? props.groupTherapy?.about.slice(0, 100) + '...' : ''
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
    <div class="rounded p-2 max-w-[350px] min-w-[250px] w-full shadow-sm bg-stone-200">
        <div class="text-center mx-auto font-bold w-fit bg-gradient-to-r from-slate-800 to-gray-600 my-2 bg-clip-text text-transparent capitalize">{{ groupTherapy.name }}</div>
        
        <div class="my-2 w-full h-1 rounded bg-stone-400"></div>
        
        <div class="py-2 my-2 max-h-[300px] overflow-hidden overflow-y-auto">
            <div v-if="computedCanViewPage" class="p-2 flex justify-end">
                <StyledLink :text="'go to group therapy'" :href="route('group.therapies.get', { groupTherapyId: groupTherapy.id})"/>
            </div>
            <div class="p-2">
                <div class="text-sm text-gray-600 text-center p-4 rounded bg-stone-100"
                >
                    {{ groupTherapy.counsellorsCount ? `${groupTherapy.counsellorsCount} counsellor${groupTherapy.counsellorsCount == 1 ? '' : 's'}` : 'no counsellors yet'}}
                </div>
            </div>

            <div class="p-2 mx-2 rounded bg-stone-100 my-4">
                <div
                    class="text-sm text-gray-600"
                >
                    <div>
                        <div class="mb-2 font-semibold">About</div>
                        <div class="text-gray-600 text-justify max-h-[100px] py-2 overflow-hidden overflow-y-auto text-sm">{{ computedAbout }}</div>
                    </div>

                    <template
                        v-if="showDetails"
                    >
                        <div class="my-2 font-semibold">Other Information</div>
                        <div
                            class="mt-2 flex justify-start items-center" :class="[groupTherapy.public ? 'text-green-700' : 'text-blue-700']">
                            <div 
                                class="w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2"
                                :class="[groupTherapy.public ? 'bg-green-700' : 'bg-blue-700']"
                            >
                                <div 
                                    class="w-full h-full rounded-full"
                                :class="[groupTherapy.public ? 'bg-green-300' : 'bg-blue-300']"
                                ></div>
                            </div>
                            <div>{{ groupTherapy.public ? 'a ' : 'not a ' }}public group therapy</div>
                        </div>

                        <div
                            class="mt-2 flex justify-start items-center" :class="[groupTherapy.anonymous ? 'text-green-700' : 'text-blue-700']">
                            <div 
                                class="w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2"
                                :class="[groupTherapy.anonymous ? 'bg-green-700' : 'bg-blue-700']"
                            >
                                <div 
                                    class="w-full h-full rounded-full"
                                :class="[groupTherapy.anonymous ? 'bg-green-300' : 'bg-blue-300']"
                                ></div>
                            </div>
                            <div>{{ computedAnonymity }}</div>
                        </div>

                        <div
                            class="mt-2 flex justify-start items-center" :class="[groupTherapy.allowAnyone ? 'text-green-700' : 'text-blue-700']">
                            <div 
                                class="w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2"
                                :class="[groupTherapy.allowAnyone ? 'bg-green-700' : 'bg-blue-700']"
                            >
                                <div 
                                    class="w-full h-full rounded-full"
                                :class="[groupTherapy.allowAnyone ? 'bg-green-300' : 'bg-blue-300']"
                                ></div>
                            </div>
                            <div>{{ computedAllowAnyone }}</div>
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
                    :value="groupTherapy.sessionsHeld"
                    class="mt-2"
                />  
            </div>
        </div>
        <div class="my-2 w-full h-1 rounded bg-stone-400"></div>

        <div class="text-xs text-end">
            {{ groupTherapy.createdAt }}
        </div>
    </div>
</template>