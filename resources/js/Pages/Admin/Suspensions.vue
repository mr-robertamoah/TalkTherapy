<script setup>
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import SuspensionCard from '@/Components/SuspensionCard.vue'
import useAlert from '@/Composables/useAlert'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// SCRUM-359: every suspension, appeals waiting first, then those in force. Suspensions are applied
// from a report's page; lifting one and deciding an appeal need a super administrator.

defineProps({
    suspensions: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="Suspensions" />

    <div>
        <div class="my-8 w-full max-w-6xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Suspensions</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    People suspended after a report. Appeals waiting for a decision are listed first.
                    <template v-if="!canManage"> Only a super administrator can lift a suspension or decide an appeal.</template>
                </div>
            </div>

            <div class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6 space-y-3">
                <div v-if="suspensions.data.length === 0" class="text-sm text-gray-500 italic">No suspensions.</div>
                <SuspensionCard
                    v-for="suspension in suspensions.data"
                    :key="suspension.id"
                    :suspension="suspension"
                    @done="(message) => setSuccessAlertData({ message })"
                    @failed="(message) => setFailedAlertData({ message })"
                />
                <Pagination :meta="suspensions.meta" @navigate="goToPage" />
            </div>
        </div>
    </div>

    <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
</template>
