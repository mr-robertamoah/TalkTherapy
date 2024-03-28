<script setup>
import BooleanAttribute from '@/Components/BooleanAttribute.vue';
import CounsellorComponent from '@/Components/CounsellorComponent.vue';
import ActivityBadge from '@/Components/ActivityBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import UserComponent from '@/Components/UserComponent.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TherapyComponent from '@/Components/TherapyComponent.vue';

const props = defineProps({
    therapy: {
        default: null
    }
})

const filters = ref({
    sessions: false,
    topics: false
})

watch(() => filters.value.sessions, () => {
    if (filters.value.sessions && filters.value.topics)
        filters.value.topics = !filters.value.sessions
})
watch(() => filters.value.topics, () => {
    if (filters.value.topics && filters.value.sessions)
        filters.value.sessions = !filters.value.topics
})

const computedIsUser = computed(() => {
    return usePage().props.auth.user?.id == props.therapy.user?.id
})
const computedIsCounsellor = computed(() => {
    return usePage().props.auth.user?.id == props.therapy.counsellor?.id
})
const computedIsParticipant = computed(() => {
    return computedIsUser.value || computedIsCounsellor.value
})
const computedCanParticipate = computed(() => {
    const user = usePage().props.auth.user

    if (user?.id == props.therapy.user?.id || user?.counsellor) return true

    return false
})

function clickedAssistanceRequest() {
    
}
</script>

<template>
    <Head :title="'Therapy' + (therapy ? ` . ${therapy.name}` : '')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-start items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight capitalize">{{ therapy.name }}</h2>
                <div class="ml-2 lowercase text-sm"> . {{ therapy.createdAt }}</div>
            </div>
        </template>

        <div class="pt-6 pb-12">

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="overflow-hidden shadow-sm sm:rounded-lg my-8 flex flex-col space-y-4 md:space-y-0 md:grid md:grid-cols-2 md:space-x-4">
                    
                    <div class="flex space-x-4 items-start justify-start w-full overflow-hidden overflow-x-auto pb-2">

                        <div class="bg-white p-6 shrink-0 w-full">
                            <div class="text-gray-600 tracking-wide font-semibold">Background Story</div>
                            <div 
                                class="my-4 min-h-40 max-h-[500px] overflow-hidden overflow-y-auto text-sm"
                                :class="[therapy.backgroundStory ? 'text-gray-700 text-justify' : 'flex justify-center items-center text-gray-600']"
                            >
                                {{ therapy.backgroundStory ?? 'no background story' }}
                            </div>
                        </div>

                        <div class="w-full shrink-0">
                            <div class="bg-white p-6 w-full">
                                <div class="text-gray-600 tracking-wide font-semibold">Counsellor</div>
                                <div v-if="therapy.counsellor" class="my-4">
                                    <CounsellorComponent
                                        :counsellor="therapy.counsellor"
                                    />
                                </div>
                                <div v-else class="my-4 flex justify-center items-start flex-col">
                                    <PrimaryButton
                                        @click="clickedAssistanceRequest"
                                        v-if="computedCanParticipate">{{!computedIsUser ? 'request to assist' : 'send assistance request'}}</PrimaryButton>
                                    <div class="mt-2 text-sm text-center p-2 text-gray-600">no counsellor yet</div>
                                </div>
                            </div>

                            <div class="bg-white p-6 shrink-0 mt-4 w-full">
                                <div class="text-gray-600 tracking-wide font-semibold">User</div>
                                <div class="my-4">
                                    <UserComponent
                                        :user="therapy.user"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full">
                            <div class="text-gray-600 tracking-wide font-semibold">Details</div>
                            <div class="my-4">
                                <div class="flex justify-start items-center mb-6">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[110px] text-end">Payment Type:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[100px]">{{ therapy.paymentType }}</div>
                                </div>
                                <div class="flex justify-start items-center my-2">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[110px] text-end">Session Type:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[100px]">{{ therapy.sessionType }}</div>
                                </div>
                                <div class="flex justify-start items-center my-2">
                                    <div class="text-sm text-gray-600 p-2 border-b-2 border-stone-600 mr-2 min-w-[110px] text-end">Status:</div>
                                    <div class="p-2 border-stone-600 text-start min-w-[100px]">{{ therapy.status }}</div>
                                </div>

                                <hr class="my-4">
                                <div class="mb-2 mt-4 text-sm font-semibold tracking-wide">Cases</div>
                                <div class="flex p-2 items-center justify-start">
                                    <template v-if="therapy.cases?.length">
                                        <div
                                            v-for="l in therapy.cases"
                                            :key="l.id"
                                            :title="l.about ?? ''"
                                            class="capitalize mr-3 rounded text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                                        >{{ l.name }}</div>
                                    </template>
                                    <div v-else class="text-gray-600 text-sm text-center my-2">there are no therapy cases added.</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full">
                            <div class="text-gray-600 tracking-wide font-semibold">Other Details</div>
                            <div class="text-gray-600 text-sm mt-2">green means true and otherwise false</div>
                            <div class="my-4">
                                <BooleanAttribute
                                    :text="'anonymous'"
                                    :condition="therapy.anonymous"
                                    class="my-2"
                                />
                                <BooleanAttribute
                                    :text="'allow in persion session'"
                                    :condition="therapy.allowInPerson"
                                    class="my-2"
                                />
                                <BooleanAttribute
                                    :text="'is public'"
                                    :condition="therapy.public"
                                    class="my-2"
                                />
                            </div>
                        </div>

                        <div class="bg-white p-6 shrink-0 w-full">
                            <div class="text-gray-600 tracking-wide font-semibold">Stats</div>
                            <div class="my-4">
                                <ActivityBadge
                                    :name="'maximum allowed sessions'"
                                    :value="therapy.maxSessions"
                                    class="my-2"
                                />
                                <ActivityBadge
                                    :name="'total sessions'"
                                    :value="therapy.sessionsCreated"
                                    class="my-2 ml-6 mt-4"
                                />
                                <ActivityBadge
                                    :name="'held sessions'"
                                    :value="therapy.sessionsHeld"
                                    class="my-2 mt-4"
                                />
                                <ActivityBadge
                                    :name="'free sessions held'"
                                    :value="therapy.freeSessions"
                                    class="my-2 mt-4 ml-6"
                                />
                                <ActivityBadge
                                    :name="'paid sessions held'"
                                    :value="therapy.paidSessions"
                                    class="my-2 mt-4"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded">
                        <div
                            class=""
                        >
                            <div class="text-gray-600 font-bold capitalize">Filter By</div>
                        
                            <div class="mt-4">
                                <label class="flex items-center mb-2">
                                    <Checkbox v-model:checked="filters.sessions"></Checkbox>
                                    <span class="text-gray-600 ml-2">Sessions</span>
                                </label>
                                <label class="flex items-center">
                                    <Checkbox v-model:checked="filters.topics"></Checkbox>
                                    <span class="text-gray-600 ml-2">Topics</span>
                                </label>
                            </div>
                        </div>
                        <div class="my-2 w-full h-1 rounded bg-stone-400"></div>
                        <TherapyComponent
                            :therapy="therapy"
                        />
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8" v-if="computedIsParticipant">
                <div class="p-6 bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">Actions</div>

                    <div class="flex space-x-2 justify-start items-center w-full overflow-hidden overflow-x-auto p-2">
                        <PrimaryButton class="shrink-0" v-if="$page.props.auth.user">report</PrimaryButton>
                        <template v-if="therapy.status !== 'ENDED'">
                            <PrimaryButton class="shrink-0" v-if="computedIsCounsellor">create session</PrimaryButton>
                            <PrimaryButton class="shrink-0">end therapy</PrimaryButton>
                            <PrimaryButton class="shrink-0">update therapy</PrimaryButton>
                            <DangerButton class="shrink-0">delete therapy</DangerButton>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>