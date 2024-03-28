<script setup>
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue';
import StarredCounsellorComponent from '@/Components/StarredCounsellorComponent.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeMount, provide, ref, watch } from 'vue';

const props = defineProps({
    recentTherapies: {
        default: []
    },
    bestCounsellors: {
        default: []
    },
    leadingCounsellors: {
        default: []
    }
})

const newTherapy = ref(null)
const recentTherapies = ref([])

watch(() => newTherapy.value, () => {
    if (newTherapy.value)
        recentTherapies.value = [newTherapy.value, ...recentTherapies.value]
})

onBeforeMount(() => {
    if (props.recentTherapies?.length)
        recentTherapies.value = [...props.recentTherapies]
    
    if (props.recentTherapies?.data?.length)
        recentTherapies.value = [...props.recentTherapies.data]
})

provide('onCreatedNewTherapy', { newTherapy, updateNewTherapy })

function updateNewTherapy(value) {
    newTherapy.value = value
}

const computedBestCounsellors = computed(() => {
    return props.bestCounsellors.data?.length ? props.bestCounsellors.data : props.bestCounsellors
})

const computedLeadingCounsellors = computed(() => {
    return props.leadingCounsellors.data?.length ? props.leadingCounsellors.data : props.leadingCounsellors
})
</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout>
        <div class="pt-6 pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8" v-if="$page.props.auth.user">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">Your recent therapies</div>
                    <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-2 flex justify-start items-center" v-if="recentTherapies?.length">
                        <MiniTherapyComponent
                            v-for="therapy in recentTherapies"
                            :key="therapy.id"
                            :therapy="therapy"
                            class="w-[250px]"
                        />
                    </div>
                    <div v-else class="text-center text-sm w-full my-4 text-gray-600">there are no therapies</div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">Starred Counsellors (previous month)</div>
                    <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-2 flex justify-start items-center" v-if="computedBestCounsellors?.length">
                        <StarredCounsellorComponent
                            v-for="(counsellor, idx) in computedBestCounsellors"
                            :key="counsellor.id"
                            :position="idx + 1"
                            :counsellor="counsellor"
                            :showStars="false"
                            class="w-[250px]"
                        />
                    </div>
                    <div v-else class="text-center text-sm w-full my-4 text-gray-600">there are no best counsellors for the previous month.</div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">Leading Counsellors (current month)</div>
                    <div class="m-2 p-2 overflow-hidden overflow-x-auto space-x-2 flex justify-start items-center" v-if="computedLeadingCounsellors?.length">
                        <StarredCounsellorComponent
                            v-for="(counsellor, idx) in computedLeadingCounsellors"
                            :key="counsellor.id"
                            :position="idx + 1"
                            :counsellor="counsellor"
                            class="w-[250px]"
                        />
                    </div>
                    <div v-else class="text-center text-sm w-full my-4 text-gray-600">there are no leading counsellors for this month.</div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4 flex justify-center">
                <div class="">

                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
