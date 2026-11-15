<script setup>
import { Head, router } from '@inertiajs/vue3'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import ReportStatusBadge from '@/Components/ReportStatusBadge.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// SCRUM-340: the complaint-review queue. Active reports (open, then under review, oldest first) by
// default; each status can be listed on its own. Reviewing happens on the per-report page.

const props = defineProps({
    search: { type: String, default: '' },
    searchTooMany: { type: Boolean, default: false },
    reports: { type: Object, required: true },
    status: { type: String, default: null },
    statuses: { type: Array, required: true },
})

const filters = [
    { value: null, label: 'active' },
    ...props.statuses.map((value) => ({ value, label: value.replace('_', ' ') })),
]

function filterBy(status) {
    router.get(route('administrator.reports'), status ? { status } : {}, { preserveScroll: true })
}

// SCRUM-430: the shared admin table.
const columns = [
    { label: '#' },
    { label: 'About' },
    { label: 'Reported by' },
    { label: 'Reported person' },
    { label: 'Status' },
    { label: 'Submitted' },
    { label: 'Actions', srOnly: true },
]

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '--'
}

// SCRUM-390: the page's search, kept in the URL with its other filters.
function searchFor(term) {
    searchPage('administrator.reports', term, { status: props.status ?? undefined })
}
</script>

<template>
    <Head title="Reports" />

    <div>
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Reports</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Complaints raised by clients and counsellors. Open a report to review it, record who it is
                    about and, when needed, read the reported conversation.
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by the reporter's name or username, or the therapy's name" :too-many="searchTooMany" @search="searchFor" />

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in filters"
                    :key="filter.label"
                    type="button"
                    class="px-3 py-1 text-sm rounded-md border capitalize transition duration-75"
                    :class="status === filter.value
                        ? 'bg-gray-800 text-white border-gray-800'
                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                    @click="() => filterBy(filter.value)"
                >{{ filter.label }}</button>
            </div>

            <div class="space-y-4">
                <AdminTable :columns="columns" caption="Reports" :empty="reports.data.length === 0" empty-text="No reports here.">
                    <AdminRow v-for="report in reports.data" :key="report.id">
                        <AdminCell label="#" half><span class="text-gray-500">{{ report.id }}</span></AdminCell>
                        <template v-if="report.involved">
                            <!-- AdminCell can't span columns: the note sits in "About" and the two cells after it stay empty (hidden when stacked). -->
                            <AdminCell label="About">
                                <span class="text-gray-600 italic">You are part of this report, so another administrator reviews it.</span>
                            </AdminCell>
                            <AdminCell class="hidden md:table-cell" />
                            <AdminCell class="hidden md:table-cell" />
                            <AdminCell label="Status" half><ReportStatusBadge :status="report.status" /></AdminCell>
                            <AdminCell label="Submitted" half><span class="whitespace-nowrap">{{ formatDate(report.createdAt) }}</span></AdminCell>
                            <AdminCell actions />
                        </template>
                        <template v-else>
                            <AdminCell label="About">
                                <div class="font-medium text-gray-900 capitalize">{{ report.subject.type }}</div>
                                <div v-if="report.therapy" class="text-gray-500">{{ report.therapy.name }}</div>
                                <div class="text-gray-600 mt-1 line-clamp-2 md:max-w-[280px]">{{ report.description }}</div>
                            </AdminCell>
                            <AdminCell label="Reported by">{{ report.reporter?.name ?? '--' }}</AdminCell>
                            <AdminCell label="Reported person">
                                <span v-if="report.reportedPerson">{{ report.reportedPerson.name }}</span>
                                <span v-else class="text-amber-700">not recorded</span>
                            </AdminCell>
                            <AdminCell label="Status" half><ReportStatusBadge :status="report.status" /></AdminCell>
                            <AdminCell label="Submitted" half><span class="whitespace-nowrap">{{ formatDate(report.createdAt) }}</span></AdminCell>
                            <AdminCell actions>
                                <AdminActionButton
                                    tone="primary"
                                    :href="route('administrator.reports.show', { report: report.id })"
                                    :aria-label="`Review report #${report.id}`"
                                >Review</AdminActionButton>
                            </AdminCell>
                        </template>
                    </AdminRow>
                </AdminTable>

                <Pagination :meta="reports.meta" @navigate="goToPage" />
            </div>
        </div>
    </div>
</template>
