<script setup>
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import SuspensionCard from '@/Components/SuspensionCard.vue'
import Modal from '@/Components/Modal.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminSuspensionRow from '@/Components/Admin/AdminSuspensionRow.vue'
import useAlert from '@/Composables/useAlert'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// SCRUM-359: every suspension, appeals waiting first, then those in force. Suspensions are applied
// from a report's page; lifting one and deciding an appeal need a super administrator.

const props = defineProps({
    search: { type: String, default: '' },
    searchTooMany: { type: Boolean, default: false },
    suspensions: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}

// SCRUM-430: a table; a row's details and actions open in a dialog holding the suspension's card,
// looked up by id so it shows the page's fresh copy after an action reloads it.
const columns = [
    { label: 'Person' },
    { label: 'Scope' },
    { label: 'Status' },
    { label: 'Period' },
    { label: 'Applied by' },
    { label: 'Actions', srOnly: true },
]
const openId = ref(null)
const opened = computed(() => props.suspensions.data.find((s) => s.id === openId.value) ?? null)

function done(message) {
    openId.value = null
    setSuccessAlertData({ message })
}

// SCRUM-390: the page's search, kept in the URL with its other filters.
function searchFor(term) {
    searchPage('administrator.suspensions', term, {})
}
</script>

<template>
    <Head title="Suspensions" />

    <div>
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Suspensions</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    People suspended after a report. Appeals waiting for a decision are listed first.
                    <template v-if="!canManage"> Only a super administrator can lift a suspension or decide an appeal.</template>
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by the suspended person's name or username" :too-many="searchTooMany" @search="searchFor" />

            <div class="space-y-4">
                <AdminTable :columns="columns" caption="Suspensions" :empty="suspensions.data.length === 0" empty-text="No suspensions.">
                    <AdminSuspensionRow
                        v-for="suspension in suspensions.data"
                        :key="suspension.id"
                        :suspension="suspension"
                        @open="(s) => openId = s.id"
                    />
                </AdminTable>
                <Pagination :meta="suspensions.meta" @navigate="goToPage" />
            </div>
        </div>
    </div>

    <Modal :show="!!opened" max-width="2xl" labelledby="suspension-dialog-title" @close="() => openId = null">
        <div v-if="opened" class="p-4 sm:p-6 space-y-3">
            <h2 id="suspension-dialog-title" class="text-lg font-semibold text-gray-900">Suspension</h2>
            <SuspensionCard
                :suspension="opened"
                @done="done"
                @failed="(message) => setFailedAlertData({ message })"
            />
            <div class="flex justify-end">
                <button type="button" class="min-h-[44px] px-4 rounded-md border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50" @click="() => openId = null">Close</button>
            </div>
        </div>
    </Modal>

    <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
</template>
